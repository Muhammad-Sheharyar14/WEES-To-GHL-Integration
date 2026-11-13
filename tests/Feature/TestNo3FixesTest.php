<?php

namespace Tests\Feature;

use App\Models\GhlToken;
use App\Models\SyncedAppointment;
use App\Models\WessConfig;
use App\Services\Ghl\GhlCalendarService;
use App\Services\Wess\WessClient;
use App\Services\Wess\WessSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TestNo3FixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // Create standard mock GHL tokens for test locations
        foreach (['loc_filter_test', 'loc_hybrid_test', 'loc_empty_schedule_test', 'loc_reassign_test', 'loc_clash_test'] as $loc) {
            GhlToken::create([
                'location_id' => $loc,
                'access_token' => 'mock_ghl_token_'.$loc,
                'refresh_token' => 'mock_refresh_token',
                'user_type' => 'Location',
                'expires_at' => now()->addHours(2),
            ]);
        }
    }

    /**
     * Test 1: Verify WESS appointment fetch query uses valid status filter OP,RQ,CF,BC
     */
    public function test_wess_appointment_fetch_uses_supported_status_filter(): void
    {
        $config = WessConfig::create([
            'location_id' => 'loc_filter_test',
            'api_token' => 'token_test_123',
            'base_url' => 'https://api.wessconnect.com/api/v1/online',
            'branch_id' => 1,
            'is_sync_enabled' => true,
            'is_connected' => true,
        ]);

        $capturedQuery = null;

        Http::fake([
            'https://api.wessconnect.com/api/v1/online/branches/1/appointments*' => function ($request) use (&$capturedQuery) {
                $capturedQuery = $request->url();

                return Http::response(['data' => []], 200);
            },
            'https://services.leadconnectorhq.com/*' => Http::response([], 200),
        ]);

        $service = app(WessSyncService::class);
        $result = $service->syncLocation($config);

        $this->assertEquals('success', $result['status']);
        $this->assertNotNull($capturedQuery);
        // Assert status filter does not contain invalid statuses (CI, CO, LM, NS, VD)
        $this->assertStringNotContainsString('CI', $capturedQuery);
        $this->assertStringNotContainsString('CO', $capturedQuery);
        $this->assertStringNotContainsString('LM', $capturedQuery);
        $this->assertStringNotContainsString('NS', $capturedQuery);
        $this->assertStringNotContainsString('VD', $capturedQuery);
        $this->assertStringContainsString('filter%5Bstatus%5D=OP%2CRQ%2CCF%2CBC', $capturedQuery);
    }

    /**
     * Test 2: Hybrid Cancellation Sync - verifies with customer transactions (status LM / VD)
     */
    public function test_hybrid_cancellation_verifies_with_customer_transactions(): void
    {
        $config = WessConfig::create([
            'location_id' => 'loc_hybrid_test',
            'api_token' => 'token_hybrid_123',
            'base_url' => 'https://api.wessconnect.com/api/v1/online',
            'branch_id' => 1,
            'is_sync_enabled' => true,
            'is_connected' => true,
        ]);

        // Existing active booking in our DB
        $synced = SyncedAppointment::create([
            'location_id' => 'loc_hybrid_test',
            'wess_appointment_id' => '99001',
            'wess_customer_id' => '1144790',
            'ghl_appointment_id' => 'ghl_appt_99001',
            'ghl_contact_id' => 'cnt_martin_wong',
            'calendar_id' => 'cal_therapist_pamela',
            'status' => 'confirmed',
            'start_time' => now()->addDays(2)->format('Y-m-d H:i:s'),
            'sync_hash' => 'hash_99001',
        ]);

        $hasGhlCancelled = false;
        $hasCustomerTxChecked = false;

        Http::fake([
            // Branch schedule returns NO appointments (meaning #99001 is missing from schedule)
            'https://api.wessconnect.com/api/v1/online/branches/1/appointments*' => Http::response([
                'data' => [
                    // An unrelated appointment to ensure list is not completely empty
                    [
                        'id' => 88888,
                        'date' => now()->addDays(5)->format('Y-m-d H:i:s'),
                        'status' => 'Open',
                        'customer' => ['id' => 222, 'first_name' => 'Other', 'last_name' => 'Client'],
                    ],
                ],
            ], 200),

            // Customer sales transaction endpoint returns LM status for Martin Wong
            'https://api.wessconnect.com/api/v1/online/branches/1/customers/1144790/sales/transaction*' => function ($request) use (&$hasCustomerTxChecked) {
                $hasCustomerTxChecked = true;

                return Http::response([
                    'data' => [
                        [
                            'id' => 28406686,
                            'date' => now()->addDays(2)->format('Y-m-d H:i:s'),
                            'status' => 'LM',
                            'total' => '0.0000',
                            'items' => [['product_id' => 329401, 'product' => ['name' => 'MC Generic Service']]],
                        ],
                    ],
                ], 200);
            },

            // GHL appointment cancellation
            'https://services.leadconnectorhq.com/calendars/events/appointments/ghl_appt_99001*' => function ($request) use (&$hasGhlCancelled) {
                $hasGhlCancelled = true;

                return Http::response(['success' => true], 200);
            },

            'https://services.leadconnectorhq.com/*' => Http::response([], 200),
        ]);

        $service = app(WessSyncService::class);
        $result = $service->syncLocation($config);

        $this->assertEquals('success', $result['status']);
        $this->assertTrue($hasCustomerTxChecked, 'Expected customer sales transaction to be queried for verification');
        $this->assertTrue($hasGhlCancelled, 'Expected GHL appointment to be cancelled once LM was confirmed');

        $synced->refresh();
        $this->assertEquals('cancelled', $synced->status);
    }

    /**
     * Test 2b: Hybrid cancellation works even when branch schedule returns 0 appointments (e.g. all cancelled)
     */
    public function test_hybrid_cancellation_works_when_branch_schedule_is_empty(): void
    {
        $config = WessConfig::create([
            'location_id' => 'loc_empty_schedule_test',
            'api_token' => 'token_empty_123',
            'base_url' => 'https://api.wessconnect.com/api/v1/online',
            'branch_id' => 1,
            'is_sync_enabled' => true,
            'is_connected' => true,
        ]);

        $synced = SyncedAppointment::create([
            'location_id' => 'loc_empty_schedule_test',
            'wess_appointment_id' => '4603',
            'wess_customer_id' => '8231',
            'ghl_appointment_id' => 'ghl_appt_4603',
            'calendar_id' => 'cal_lavender',
            'status' => 'confirmed',
            'start_time' => now()->addDays(4)->format('Y-m-d H:i:s'),
            'sync_hash' => 'hash_4603',
        ]);

        $hasGhlCancelled = false;
        $hasCustomerTxChecked = false;

        Http::fake([
            // WESS branch schedule returns COMPLETELY EMPTY (0 appointments returned)
            'https://api.wessconnect.com/api/v1/online/branches/1/appointments*' => Http::response([
                'data' => [],
            ], 200),

            // Customer sales transaction endpoint confirms LM
            'https://api.wessconnect.com/api/v1/online/branches/1/customers/8231/sales/transaction*' => function ($request) use (&$hasCustomerTxChecked) {
                $hasCustomerTxChecked = true;

                return Http::response([
                    'data' => [
                        [
                            'id' => 4603,
                            'date' => now()->addDays(4)->format('Y-m-d H:i:s'),
                            'status' => 'LM',
                            'total' => '0.0000',
                        ],
                    ],
                ], 200);
            },

            // GHL appointment cancellation
            'https://services.leadconnectorhq.com/calendars/events/appointments/ghl_appt_4603*' => function ($request) use (&$hasGhlCancelled) {
                $hasGhlCancelled = true;

                return Http::response(['success' => true], 200);
            },

            'https://services.leadconnectorhq.com/*' => Http::response([], 200),
        ]);

        $service = app(WessSyncService::class);
        $result = $service->syncLocation($config);

        $this->assertEquals('success', $result['status']);
        $this->assertTrue($hasCustomerTxChecked, 'Expected customer sales transaction to be queried even when branch schedule returned 0 items');
        $this->assertTrue($hasGhlCancelled, 'Expected GHL appointment to be cancelled once LM was confirmed');

        $synced->refresh();
        $this->assertEquals('cancelled', $synced->status);
    }

    /**
     * Test 3: Calendar Reassignment deletes old appointment to prevent dual strikethrough cards
     */
    public function test_calendar_reassignment_deletes_old_appointment(): void
    {
        $config = WessConfig::create([
            'location_id' => 'loc_reassign_test',
            'api_token' => 'token_reassign_123',
            'base_url' => 'https://api.wessconnect.com/api/v1/online',
            'branch_id' => 1,
            'is_sync_enabled' => true,
            'is_connected' => true,
        ]);

        // Pre-existing mapping on old calendar (e.g. Bust Me Up)
        $existing = SyncedAppointment::create([
            'location_id' => 'loc_reassign_test',
            'wess_appointment_id' => '77001',
            'ghl_appointment_id' => 'old_ghl_event_77001',
            'calendar_id' => 'cal_bust_me_up',
            'status' => 'confirmed',
            'start_time' => '2026-11-22T10:00:00.000000Z',
        ]);

        $hasDeleteCalled = false;

        Http::fake([
            // GHL DELETE endpoint on old calendar event
            'https://services.leadconnectorhq.com/calendars/events/appointments/old_ghl_event_77001' => function ($request) use (&$hasDeleteCalled) {
                if ($request->method() === 'DELETE') {
                    $hasDeleteCalled = true;

                    return Http::response(['success' => true], 200);
                }

                return Http::response(['error' => 'Not delete'], 400);
            },
            // GHL POST creating new event on therapist personal calendar
            'https://services.leadconnectorhq.com/calendars/events/appointments' => function ($request) {
                return Http::response([
                    'id' => 'new_ghl_event_aarin_77001',
                    'calendarId' => 'cal_aarin_personal',
                ], 201);
            },
            'https://services.leadconnectorhq.com/*' => Http::response([], 200),
        ]);

        $ghlService = app(GhlCalendarService::class);
        $wessAppointment = [
            'id' => 77001,
            'date' => '2026-11-22T10:00:00.000000Z',
            'status' => 'Open',
            'customer' => ['id' => 501, 'first_name' => 'Jane', 'last_name' => 'Doe'],
        ];

        $res = $ghlService->syncAppointmentToGhl(
            'loc_reassign_test',
            $wessAppointment,
            true,
            'cal_aarin_personal', // New therapist personal calendar
            'usr_aarin'
        );

        $this->assertTrue($hasDeleteCalled, 'Expected DELETE to be called on old calendar event during reassignment');
    }

    /**
     * Test 4: Slot collision cancels CRM appointment and creates note + tag
     */
    public function test_slot_collision_cancels_crm_and_adds_note_and_tag(): void
    {
        $config = WessConfig::create([
            'location_id' => 'loc_clash_test',
            'api_token' => 'token_clash_123',
            'base_url' => 'https://api.wessconnect.com/api/v1/online',
            'branch_id' => 1,
            'is_sync_enabled' => true,
            'is_connected' => true,
        ]);

        $hasCancelledCrm = false;
        $hasNoteAdded = false;
        $hasTagAdded = false;

        Http::fake([
            // WESS returns Pamela with existing appointment for TJ Foo at 11:00 AM
            'https://api.wessconnect.com/api/v1/online/branches/1/appointments*' => Http::response([
                'data' => [
                    [
                        'id' => 10101,
                        'date' => '2026-11-22T03:00:00.000000Z', // 11:00 AM SGT (UTC+8)
                        'status' => 'Open',
                        'processing_time' => 60,
                        'customer' => ['id' => 301, 'first_name' => 'TJ', 'last_name' => 'Foo'],
                        'employees' => [['id' => 105, 'first_name' => 'Pamela']],
                    ],
                ],
            ], 200),

            // WESS services endpoint
            'https://api.wessconnect.com/api/v1/online/branches/1/appointments/services*' => Http::response([
                'data' => [
                    ['id' => 329401, 'code' => 'S01007', 'name' => 'MC Generic Service'],
                ],
            ], 200),

            // WESS Employees endpoint mapping Pamela
            'https://api.wessconnect.com/api/v1/online/branches/1/appointments/employees*' => Http::response([
                'data' => [
                    ['id' => 105, 'first_name' => 'Pamela', 'last_name' => 'EP01002', 'code' => 'EP01002'],
                ],
            ], 200),

            // CRM Users
            'https://services.leadconnectorhq.com/users*' => Http::response([
                'users' => [
                    ['id' => 'usr_pamela', 'name' => 'Pamela EP01002', 'firstName' => 'Pamela', 'lastName' => 'EP01002'],
                ],
            ], 200),

            // WESS customer lookup
            'https://api.wessconnect.com/api/v1/online/customers/lookup/phone-number/*' => Http::response([
                [
                    'id' => 401,
                    'first_name' => 'Laven',
                    'last_name' => 'Woo',
                    'phone_mobile' => '+6591234567',
                ],
            ], 200),

            // CRM Cancel appointment
            'https://services.leadconnectorhq.com/calendars/events/appointments/ghl_appt_laven*' => function ($request) use (&$hasCancelledCrm) {
                $hasCancelledCrm = true;

                return Http::response(['success' => true], 200);
            },

            // CRM Add Note
            'https://services.leadconnectorhq.com/contacts/cnt_laven_woo/notes' => function ($request) use (&$hasNoteAdded) {
                $hasNoteAdded = true;

                return Http::response(['success' => true], 201);
            },

            // CRM Add Tag
            'https://services.leadconnectorhq.com/contacts/cnt_laven_woo/tags' => function ($request) use (&$hasTagAdded) {
                $hasTagAdded = true;

                return Http::response(['success' => true], 200);
            },

            'https://services.leadconnectorhq.com/*' => Http::response([], 200),
        ]);

        // Incoming GHL Webhook: Laven Woo booked 10:00 AM - 11:15 AM under Pamela (overlaps with 11:00 AM)
        $payload = [
            'type' => 'AppointmentCreate',
            'locationId' => 'loc_clash_test',
            'appointment' => [
                'id' => 'ghl_appt_laven',
                'contactId' => 'cnt_laven_woo',
                'startTime' => '2026-11-22T10:00:00+08:00',
                'endTime' => '2026-11-22T11:15:00+08:00',
                'assignedUserId' => 'usr_pamela',
            ],
            'contact' => [
                'id' => 'cnt_laven_woo',
                'firstName' => 'Laven',
                'lastName' => 'Woo',
                'phone' => '+6591234567',
            ],
        ];

        $response = $this->postJson('/api/webhook', $payload);
        $response->assertStatus(200);

        $this->assertTrue($hasCancelledCrm, 'Expected CRM appointment to be cancelled on slot clash');
        $this->assertTrue($hasNoteAdded, 'Expected internal note to be added to contact on slot clash');
        $this->assertTrue($hasTagAdded, 'Expected wess-slot-clash tag to be added to contact on slot clash');
    }

    /**
     * Test 5: Phone country code is normalized with leading '+'
     */
    public function test_phone_country_code_normalized_with_plus(): void
    {
        $parsed1 = WessClient::parsePhoneNumber('+6591234567');
        $this->assertEquals('+65', $parsed1['country_code']);
        $this->assertEquals('91234567', $parsed1['mobile']);

        $parsed2 = WessClient::parsePhoneNumber('91234567');
        $this->assertEquals('+65', $parsed2['country_code']);
        $this->assertEquals('91234567', $parsed2['mobile']);

        $parsed3 = WessClient::parsePhoneNumber('+60123456789');
        $this->assertEquals('+60', $parsed3['country_code']);
        $this->assertEquals('123456789', $parsed3['mobile']);

        $client = new WessClient('test_token', 'https://api.wessconnect.com/api/v1/online');
        $capturedPayload = null;

        Http::fake([
            'https://api.wessconnect.com/api/v1/online/branches/1/customers' => function ($request) use (&$capturedPayload) {
                $capturedPayload = $request->data();

                return Http::response(['data' => ['id' => 777]], 201);
            },
        ]);

        $client->createCustomer(1, [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'phone_mobile_country_code' => '65', // Sent without plus
            'phone_mobile' => '91234567',
        ]);

        $this->assertNotNull($capturedPayload);
        $this->assertEquals('+65', $capturedPayload['phone_mobile_country_code']);
    }
}
