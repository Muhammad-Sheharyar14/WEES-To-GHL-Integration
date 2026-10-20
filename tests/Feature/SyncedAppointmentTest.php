<?php

namespace Tests\Feature;

use App\Models\GhlToken;
use App\Models\SyncedAppointment;
use App\Models\WessConfig;
use App\Services\Wess\WessSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncedAppointmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        GhlToken::create([
            'location_id'   => 'loc_test_sync',
            'access_token'  => 'mock_access_token',
            'refresh_token' => 'mock_refresh_token',
            'user_type'     => 'Location',
            'expires_at'    => now()->addHours(2),
            'is_active'     => true,
        ]);

        WessConfig::create([
            'location_id'     => 'loc_test_sync',
            'api_token'       => 'test_wess_token',
            'base_url'        => 'https://api.prelive.wessconnect.net/api/v1/online',
            'branch_id'       => 1,
            'calendar_id'     => 'cal_wess_123',
            'is_sync_enabled' => true,
            'is_connected'    => true,
        ]);
    }

    public function test_first_sync_creates_appointment_and_records_to_database(): void
    {
        Http::fake([
            'https://api.prelive.wessconnect.net/api/v1/online/branches/1/appointments*' => Http::response([
                'data' => [
                    [
                        'id'              => 101,
                        'date'            => '2026-10-15T09:00:00Z',
                        'status'          => 'confirmed',
                        'processing_time' => 45,
                        'service_name'    => 'Hair Spa',
                        'customer'        => [
                            'first_name'   => 'Jane',
                            'last_name'    => 'Doe',
                            'phone_mobile' => '+60123456789',
                        ],
                    ],
                ],
                'meta' => [
                    'pagination' => [
                        'total'        => 1,
                        'count'        => 1,
                        'per_page'     => 100,
                        'current_page' => 1,
                        'total_pages'  => 1,
                    ],
                ],
            ], 200),
            'https://services.leadconnectorhq.com/contacts/upsert' => Http::response([
                'contact' => ['id' => 'ghl_contact_jane'],
            ], 200),
            'https://services.leadconnectorhq.com/calendars/events/appointments' => Http::response([
                'id'         => 'ghl_appt_jane_101',
                'calendarId' => 'cal_wess_123',
                'status'     => 'confirmed',
            ], 200),
        ]);

        $service = app(WessSyncService::class);
        $results = $service->syncAllLocations('loc_test_sync');

        $this->assertEquals(1, $results['loc_test_sync']['synced_count']);
        $this->assertEquals(0, $results['loc_test_sync']['skipped_unchanged']);

        // Assert record exists in synced_appointments DB table
        $this->assertDatabaseHas('synced_appointments', [
            'location_id'         => 'loc_test_sync',
            'wess_appointment_id' => '101',
            'ghl_appointment_id'  => 'ghl_appt_jane_101',
            'ghl_contact_id'      => 'ghl_contact_jane',
            'calendar_id'         => 'cal_wess_123',
        ]);
    }

    public function test_second_sync_skips_unchanged_appointment_using_database(): void
    {
        $date = '2026-10-15T09:00:00Z';
        $status = 'confirmed';
        $time = 45;
        $service = 'Hair Spa';
        $custName = 'Jane Doe';
        $hash = md5($date . $status . $time . $service . $custName);

        // Pre-populate synced appointment record in DB
        SyncedAppointment::create([
            'location_id'         => 'loc_test_sync',
            'wess_appointment_id' => '101',
            'ghl_appointment_id'  => 'ghl_appt_jane_101',
            'ghl_contact_id'      => 'ghl_contact_jane',
            'calendar_id'         => 'cal_wess_123',
            'sync_hash'           => $hash,
            'last_synced_at'      => now(),
        ]);

        Http::fake([
            'https://api.prelive.wessconnect.net/api/v1/online/branches/1/appointments*' => Http::response([
                'data' => [
                    [
                        'id'              => 101,
                        'date'            => $date,
                        'status'          => $status,
                        'processing_time' => $time,
                        'service_name'    => $service,
                        'customer_name'   => $custName,
                    ],
                ],
                'meta' => [
                    'pagination' => [
                        'total'        => 1,
                        'count'        => 1,
                        'per_page'     => 100,
                        'current_page' => 1,
                        'total_pages'  => 1,
                    ],
                ],
            ], 200),
        ]);

        $syncService = app(WessSyncService::class);
        $results = $syncService->syncAllLocations('loc_test_sync');

        // Verified in DB: 1 skipped, 0 synced, 0 HTTP calls to GHL appointments endpoint
        $this->assertEquals(0, $results['loc_test_sync']['synced_count']);
        $this->assertEquals(1, $results['loc_test_sync']['skipped_unchanged']);

        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), 'calendars/events/appointments');
        });
    }

    public function test_sync_updates_existing_appointment_via_put_when_changed(): void
    {
        $oldHash = md5('old_data');

        SyncedAppointment::create([
            'location_id'         => 'loc_test_sync',
            'wess_appointment_id' => '102',
            'ghl_appointment_id'  => 'ghl_appt_existing_102',
            'ghl_contact_id'      => 'ghl_contact_102',
            'calendar_id'         => 'cal_wess_123',
            'sync_hash'           => $oldHash,
            'last_synced_at'      => now()->subDay(),
        ]);

        Http::fake([
            'https://api.prelive.wessconnect.net/api/v1/online/branches/1/appointments*' => Http::response([
                'data' => [
                    [
                        'id'              => 102,
                        'date'            => '2026-10-16T14:00:00Z', // changed date
                        'status'          => 'confirmed',
                        'processing_time' => 60,
                        'service_name'    => 'Massage Therapy',
                        'customer'        => [
                            'first_name'   => 'Alex',
                            'last_name'    => 'Smith',
                            'phone_mobile' => '+60199999999',
                        ],
                    ],
                ],
                'meta' => [
                    'pagination' => [
                        'total'        => 1,
                        'count'        => 1,
                        'per_page'     => 100,
                        'current_page' => 1,
                        'total_pages'  => 1,
                    ],
                ],
            ], 200),
            'https://services.leadconnectorhq.com/contacts/upsert' => Http::response([
                'contact' => ['id' => 'ghl_contact_102'],
            ], 200),
            // PUT request to update the EXISTING appointment
            'https://services.leadconnectorhq.com/calendars/events/appointments/ghl_appt_existing_102' => Http::response([
                'id'         => 'ghl_appt_existing_102',
                'calendarId' => 'cal_wess_123',
                'status'     => 'confirmed',
            ], 200),
        ]);

        $syncService = app(WessSyncService::class);
        $results = $syncService->syncAllLocations('loc_test_sync');

        $this->assertEquals(1, $results['loc_test_sync']['synced_count']);
        $this->assertEquals(0, $results['loc_test_sync']['skipped_unchanged']);

        // Verify PUT request was dispatched (NOT POST)
        Http::assertSent(function ($request) {
            return $request->method() === 'PUT' &&
                   str_contains($request->url(), 'calendars/events/appointments/ghl_appt_existing_102');
        });

        // Ensure NO POST was sent to appointments create
        Http::assertNotSent(function ($request) {
            return $request->method() === 'POST' &&
                   $request->url() === 'https://services.leadconnectorhq.com/calendars/events/appointments';
        });

        // Verify database was updated with the new hash
        $record = SyncedAppointment::where('location_id', 'loc_test_sync')
            ->where('wess_appointment_id', '102')
            ->first();

        $this->assertNotNull($record);
        $this->assertNotEquals($oldHash, $record->sync_hash);
    }

    public function test_sync_loop_terminates_immediately_when_paused_via_master_switch(): void
    {
        $hasToggled = false;

        Http::fake([
            'https://api.prelive.wessconnect.net/api/v1/online/branches/1/appointments*' => Http::response([
                'data' => [
                    [
                        'id'              => 201,
                        'date'            => '2026-10-17T10:00:00Z',
                        'status'          => 'confirmed',
                        'processing_time' => 30,
                        'service_name'    => 'Facial Express',
                        'customer'        => [
                            'first_name'   => 'User',
                            'last_name'    => 'One',
                            'phone_mobile' => '+60111111111',
                        ],
                    ],
                    [
                        'id'              => 202,
                        'date'            => '2026-10-17T11:00:00Z',
                        'status'          => 'confirmed',
                        'processing_time' => 30,
                        'service_name'    => 'Facial Express',
                        'customer'        => [
                            'first_name'   => 'User',
                            'last_name'    => 'Two',
                            'phone_mobile' => '+60122222222',
                        ],
                    ],
                    [
                        'id'              => 203,
                        'date'            => '2026-10-17T12:00:00Z',
                        'status'          => 'confirmed',
                        'processing_time' => 30,
                        'service_name'    => 'Facial Express',
                        'customer'        => [
                            'first_name'   => 'User',
                            'last_name'    => 'Three',
                            'phone_mobile' => '+60133333333',
                        ],
                    ],
                ],
                'meta' => [
                    'pagination' => [
                        'total'        => 3,
                        'count'        => 3,
                        'per_page'     => 100,
                        'current_page' => 1,
                        'total_pages'  => 1,
                    ],
                ],
            ], 200),
            'https://services.leadconnectorhq.com/contacts/upsert' => Http::response([
                'contact' => ['id' => 'ghl_contact_mock'],
            ], 200),
            'https://services.leadconnectorhq.com/calendars/events/appointments' => function ($request) use (&$hasToggled) {
                // Simulate user turning off sync on custom page after first appointment is dispatched!
                if (!$hasToggled) {
                    $hasToggled = true;
                    WessConfig::where('location_id', 'loc_test_sync')->update(['is_sync_enabled' => false]);
                }

                return Http::response([
                    'id'         => 'ghl_appt_first',
                    'calendarId' => 'cal_wess_123',
                    'status'     => 'confirmed',
                ], 200);
            },
        ]);

        $syncService = app(WessSyncService::class);
        $results = $syncService->syncAllLocations('loc_test_sync');

        // Only 1 appointment was processed before termination
        $this->assertEquals(1, $results['loc_test_sync']['synced_count']);
        $this->assertTrue($results['loc_test_sync']['stopped_early']);
        $this->assertEquals('stopped', $results['loc_test_sync']['status']);

        // Appointments 202 and 203 must NEVER have been synced
        $this->assertDatabaseHas('synced_appointments', [
            'location_id'         => 'loc_test_sync',
            'wess_appointment_id' => '201',
        ]);
        $this->assertDatabaseMissing('synced_appointments', [
            'location_id'         => 'loc_test_sync',
            'wess_appointment_id' => '202',
        ]);
        $this->assertDatabaseMissing('synced_appointments', [
            'location_id'         => 'loc_test_sync',
            'wess_appointment_id' => '203',
        ]);

        // Verify stopped log was recorded
        $this->assertDatabaseHas('webhook_logs', [
            'location_id' => 'loc_test_sync',
            'event_type'  => 'WessSyncStoppedByUser',
        ]);
    }
}

