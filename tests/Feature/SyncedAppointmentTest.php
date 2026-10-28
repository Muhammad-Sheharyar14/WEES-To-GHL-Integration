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

    public function test_layer2_reconciliation_prevents_duplicate_when_mapping_table_is_empty(): void
    {
        // 1. synced_appointments table is completely empty (e.g. after uninstall / reconnect)
        $this->assertEquals(0, SyncedAppointment::count());

        $hasPutCalled  = false;
        $hasPostCalled = false;

        Http::fake([
            // WESS API returns appointment #777
            'https://api.prelive.wessconnect.net/api/v1/online/branches/1/appointments*' => Http::response([
                'data' => [
                    [
                        'id'              => 777,
                        'date'            => '2026-10-26T10:00:00.000000Z',
                        'status'          => 'confirmed',
                        'processing_time' => 30,
                        'service_name'    => 'Deep Tissue Massage',
                        'customer'        => [
                            'first_name'   => 'Sherry',
                            'last_name'    => 'Test',
                            'phone_mobile' => '+6581234567',
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
            // CRM calendars/events returns existing appointment in CRM with matching WESS Booking ID note
            'https://services.leadconnectorhq.com/calendars/events?*' => Http::response([
                'events' => [
                    [
                        'id'                => 'ghl_existing_event_777',
                        'calendarId'        => 'cal_wess_123',
                        'locationId'        => 'loc_test_sync',
                        'contactId'         => 'cnt_sherry_123',
                        'startTime'         => '2026-10-26T10:00:00.000Z',
                        'endTime'           => '2026-10-26T10:30:00.000Z',
                        'title'             => 'Sherry Test\'s Appointment',
                        'appointmentStatus' => 'confirmed',
                        'notes'             => 'WESS Booking ID: 777',
                    ],
                ],
            ], 200),
            'https://services.leadconnectorhq.com/contacts/upsert' => Http::response([
                'contact' => ['id' => 'cnt_sherry_123'],
            ], 200),
            // If GHL receives a POST to create appointment, fail the test
            'https://services.leadconnectorhq.com/calendars/events/appointments' => function ($request) use (&$hasPostCalled) {
                if ($request->method() === 'POST') {
                    $hasPostCalled = true;
                }
                return Http::response(['id' => 'unexpected_duplicate'], 201);
            },
            // If GHL receives a PUT to update appointment, record it
            'https://services.leadconnectorhq.com/calendars/events/appointments/ghl_existing_event_777' => function ($request) use (&$hasPutCalled) {
                if ($request->method() === 'PUT') {
                    $hasPutCalled = true;
                }
                return Http::response(['id' => 'ghl_existing_event_777', 'status' => 'confirmed'], 200);
            },
        ]);

        $syncService = app(WessSyncService::class);
        $results = $syncService->syncAllLocations('loc_test_sync');

        // Verify synced_appointments was automatically rebuilt from CRM events
        $this->assertDatabaseHas('synced_appointments', [
            'location_id'         => 'loc_test_sync',
            'wess_appointment_id' => '777',
            'ghl_appointment_id'  => 'ghl_existing_event_777',
        ]);

        // CRITICAL ASSERTION: A duplicate POST was NEVER executed!
        $this->assertFalse($hasPostCalled, 'Duplicate POST appointment was executed on CRM calendar when it should have been reconciled!');
    }

    public function test_cancellation_auto_recovery_cancels_in_wess_even_if_mapping_table_empty(): void
    {
        // Ensure mapping table is empty (simulating post-uninstall / fresh state)
        $this->assertEquals(0, SyncedAppointment::count());

        $hasCancelCalled = false;
        $cancelledCustomerId = null;

        Http::fake([
            'https://api.prelive.wessconnect.net/api/v1/online/customers/lookup/phone-number/*' => Http::response([
                [
                    'id'           => 4748191,
                    'first_name'   => 'Sherry',
                    'last_name'    => 'Test',
                    'phone_mobile' => '81234567',
                ],
            ], 200),
            'https://api.prelive.wessconnect.net/api/v1/online/branches/1/appointments/28092284/cancel' => function ($request) use (&$hasCancelCalled, &$cancelledCustomerId) {
                if ($request->method() === 'PATCH') {
                    $hasCancelCalled = true;
                    $cancelledCustomerId = $request->data()['customer_id'] ?? null;
                }
                return Http::response(['success' => true], 200);
            },
        ]);

        // CRM sends cancellation webhook for an appointment created prior to uninstall
        $response = $this->postJson('/api/webhooks/ghl', [
            'type'        => 'AppointmentStatusUpdate',
            'locationId'  => 'loc_test_sync',
            'appointment' => [
                'id'                => 'ghl_appt_old_28092284',
                'appointmentStatus' => 'cancelled',
                'notes'             => 'WESS Booking ID: 28092284',
                'phone'             => '+6581234567',
                'title'             => 'Sherry Test (WESS #28092284)',
                'startTime'         => '2026-10-26T18:00:00+08:00',
            ],
        ]);

        $response->assertStatus(200);

        // Verify WESS cancel endpoint was called with correct appointment and customer ID
        $this->assertTrue($hasCancelCalled, 'WESS cancel endpoint was not called for auto-recovered appointment!');
        $this->assertEquals(4748191, $cancelledCustomerId, 'Customer ID was not resolved correctly for WESS cancellation!');

        // Verify mapping was saved to synced_appointments marked as cancelled
        $this->assertDatabaseHas('synced_appointments', [
            'location_id'         => 'loc_test_sync',
            'wess_appointment_id' => '28092284',
            'ghl_appointment_id'  => 'ghl_appt_old_28092284',
            'status'              => 'cancelled',
        ]);
    }

    public function test_catch_up_sync_recovers_missed_appointments_from_paused_date_and_resets_pointer(): void
    {
        $pausedAt = now()->subDays(20);
        $config = WessConfig::where('location_id', 'loc_test_sync')->first();
        $config->update([
            'is_sync_enabled' => true,
            'sync_paused_at'  => $pausedAt,
        ]);

        $capturedDateFrom = null;

        Http::fake([
            'https://api.prelive.wessconnect.net/api/v1/online/branches/1/appointments*' => function ($request) use (&$capturedDateFrom, $pausedAt) {
                // Extract query filter date_from from PSR-7 URI
                $queryString = $request->toPsrRequest()->getUri()->getQuery();
                parse_str($queryString, $query);
                if (empty($capturedDateFrom) && !empty($query['filter']['date_from'])) {
                    $capturedDateFrom = $query['filter']['date_from'];
                }

                return Http::response([
                    'data' => [
                        [
                            'id'              => 9901,
                            'date'            => $pausedAt->copy()->addDays(2)->toIso8601String(),
                            'status'          => 'confirmed',
                            'processing_time' => 60,
                            'service_name'    => 'Facial Treatment',
                            'customer'        => [
                                'first_name'   => 'Recovered',
                                'last_name'    => 'Client',
                                'phone_mobile' => '+6581234567',
                                'email'        => 'recovered@example.com',
                            ],
                            'employees'       => [],
                        ],
                    ],
                ], 200);
            },
            'https://services.leadconnectorhq.com/contacts/upsert' => Http::response([
                'contact' => ['id' => 'cnt_recovered_1']
            ], 200),
            'https://services.leadconnectorhq.com/calendars/events/appointments' => Http::response([
                'id'         => 'ghl_recovered_event_9901',
                'calendarId' => 'cal_wess_123',
                'status'     => 'confirmed',
            ], 200),
            'https://services.leadconnectorhq.com/calendars/events*' => Http::response([
                'events' => []
            ], 200),
            'https://services.leadconnectorhq.com/users*' => Http::response([
                'users' => []
            ], 200),
            'https://services.leadconnectorhq.com/contacts/*' => Http::response([
                'contact' => ['id' => 'cnt_recovered_1']
            ], 200),
            'https://services.leadconnectorhq.com/locations/*/customFields' => Http::response([
                'customFields' => []
            ], 200),
        ]);

        $service = app(WessSyncService::class);
        $result = $service->syncLocation($config);

        $this->assertEquals('success', $result['status']);
        $this->assertEquals(1, $result['synced_count']);

        // Verify that the query started from the paused date, not today!
        $this->assertNotNull($capturedDateFrom);
        $this->assertEquals($pausedAt->startOfDay()->format('Y-m-d H:i:s'), $capturedDateFrom);

        // Verify that the recovered appointment was synced to database
        $this->assertDatabaseHas('synced_appointments', [
            'location_id'         => 'loc_test_sync',
            'wess_appointment_id' => '9901',
            'ghl_appointment_id'  => 'ghl_recovered_event_9901',
        ]);

        // Verify that sync_paused_at was reset to null after catch-up completed
        $freshConfig = WessConfig::where('location_id', 'loc_test_sync')->first();
        $this->assertNull($freshConfig->sync_paused_at, 'sync_paused_at should be reset to null after catch-up completes!');
        $this->assertNotNull($freshConfig->last_synced_at);
    }

    public function test_wess_cancellation_status_bc_cancels_crm_appointment_during_sync(): void
    {
        $this->markTestSkipped('WESS cancellation propagation disabled per user request: WESS does not return cancelled appointments via GET.');
        $config = WessConfig::where('location_id', 'loc_test_sync')->first();

        // Existing active booking in CRM
        $synced = SyncedAppointment::create([
            'location_id'         => 'loc_test_sync',
            'wess_appointment_id' => '3501',
            'ghl_appointment_id'  => 'ghl_event_3501',
            'ghl_contact_id'      => 'cnt_jane_3501',
            'calendar_id'         => 'cal_wess_123',
            'status'              => 'confirmed',
            'start_time'          => now()->addDays(2)->format('Y-m-d H:i:s'),
            'sync_hash'           => 'old_hash',
        ]);

        $hasGhlCancelled = false;

        Http::fake([
            // WESS returns appointment 3501 with status BC (Booking Cancelled)
            'https://api.prelive.wessconnect.net/api/v1/online/branches/1/appointments*' => Http::response([
                'data' => [
                    [
                        'id'              => 3501,
                        'date'            => now()->addDays(2)->format('Y-m-d H:i:s'),
                        'status'          => 'BC',
                        'processing_time' => 60,
                        'service_name'    => 'Hair Wash',
                        'customer'        => [
                            'first_name'   => 'Jane',
                            'last_name'    => 'Doe',
                            'phone_mobile' => '+6591234567',
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
            // GHL receives cancellation for ghl_event_3501
            'https://services.leadconnectorhq.com/calendars/events/appointments/ghl_event_3501*' => function ($request) use (&$hasGhlCancelled) {
                $hasGhlCancelled = true;
                return Http::response(['success' => true], 200);
            },
            'https://services.leadconnectorhq.com/calendars/events*' => Http::response([
                'events' => []
            ], 200),
            'https://services.leadconnectorhq.com/users*' => Http::response([
                'users' => []
            ], 200),
            'https://services.leadconnectorhq.com/contacts/*' => Http::response([
                'contact' => ['id' => 'cnt_jane_3501']
            ], 200),
            'https://services.leadconnectorhq.com/locations/*/customFields' => Http::response([
                'customFields' => []
            ], 200),
        ]);

        $service = app(WessSyncService::class);
        $result = $service->syncLocation($config);

        $this->assertEquals('success', $result['status']);
        $this->assertTrue($hasGhlCancelled, 'GHL cancellation should have been triggered for cancelled WESS appointment 3501!');

        // Verify local mapping updated to cancelled
        $synced->refresh();
        $this->assertEquals('cancelled', $synced->status);
    }
}

