<?php

namespace Tests\Feature;

use App\Models\GhlToken;
use App\Models\WessConfig;
use App\Models\WebhookLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GhlWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_handles_app_install_webhook(): void
    {
        $token = GhlToken::create([
            'location_id'   => 'loc_install_123',
            'access_token'  => 'acc_token',
            'refresh_token' => 'ref_token',
            'user_type'     => 'Location',
            'expires_at'    => now()->addHour(),
            'is_active'     => false,
        ]);

        $response = $this->postJson('/api/webhook', [
            'type'       => 'INSTALL',
            'appId'      => 'app_test_123',
            'locationId' => 'loc_install_123',
            'companyId'  => 'comp_123',
            'userId'     => 'user_123',
        ]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'success']);

        $token->refresh();
        $this->assertTrue($token->is_active);
    }

    public function test_handles_app_uninstall_webhook_location_level(): void
    {
        WessConfig::create([
            'location_id' => 'loc_uninstall_123',
            'api_token'   => 'test_wess_token',
        ]);

        GhlToken::create([
            'location_id'   => 'loc_uninstall_123',
            'access_token'  => 'acc_token',
            'refresh_token' => 'ref_token',
            'user_type'     => 'Location',
            'expires_at'    => now()->addHour(),
            'is_active'     => true,
        ]);

        WebhookLog::create([
            'source'          => 'ghl_webhook',
            'location_id'     => 'loc_uninstall_123',
            'event_type'      => 'AppointmentCreate',
            'response_status' => 200,
        ]);

        $response = $this->postJson('/api/webhook', [
            'type'       => 'UNINSTALL',
            'appId'      => 'app_test_123',
            'locationId' => 'loc_uninstall_123',
        ]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'success']);

        $this->assertDatabaseMissing('wess_configs', ['location_id' => 'loc_uninstall_123']);
        $this->assertDatabaseMissing('ghl_tokens', ['location_id' => 'loc_uninstall_123']);
        $this->assertDatabaseMissing('webhook_logs', ['location_id' => 'loc_uninstall_123']);
    }

    public function test_handles_app_uninstall_webhook_agency_level(): void
    {
        GhlToken::create([
            'company_id'    => 'comp_agency_uninstall',
            'location_id'   => 'loc_sub_1',
            'access_token'  => 'acc_token_agency',
            'refresh_token' => 'ref_token_agency',
            'user_type'     => 'Company',
            'expires_at'    => now()->addHour(),
            'is_active'     => true,
        ]);

        WessConfig::create([
            'location_id' => 'loc_sub_1',
            'api_token'   => 'token_1',
        ]);

        $response = $this->postJson('/api/webhook', [
            'type'      => 'UNINSTALL',
            'appId'     => 'app_test_123',
            'companyId' => 'comp_agency_uninstall',
        ]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'success']);

        $this->assertDatabaseMissing('ghl_tokens', ['company_id' => 'comp_agency_uninstall']);
        $this->assertDatabaseMissing('wess_configs', ['location_id' => 'loc_sub_1']);
    }

    public function test_handles_appointment_delete_webhook(): void
    {
        WessConfig::create([
            'location_id'     => 'loc_appt_del',
            'api_token'       => 'test_token',
            'base_url'        => 'https://api.prelive.wessconnect.net/api/v1/online',
            'branch_id'       => 1,
            'is_sync_enabled' => true,
        ]);

        Http::fake([
            'https://api.prelive.wessconnect.net/*' => Http::response(['status' => 'cancelled'], 200),
        ]);

        $response = $this->postJson('/api/webhook', [
            'type'        => 'AppointmentDelete',
            'locationId'  => 'loc_appt_del',
            'appointment' => [
                'id'                => 'appt_999',
                'contactId'         => 'contact_123',
                'appointmentStatus' => 'deleted',
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'success']);

        $this->assertDatabaseHas('webhook_logs', [
            'location_id' => 'loc_appt_del',
            'event_type'  => 'AppointmentDelete',
            'method'      => 'PATCH',
        ]);
    }

    public function test_handles_contact_delete_webhook(): void
    {
        WessConfig::create([
            'location_id'     => 'loc_contact_del',
            'api_token'       => 'test_token',
            'is_sync_enabled' => true,
        ]);

        $response = $this->postJson('/api/webhook', [
            'type'       => 'ContactDelete',
            'locationId' => 'loc_contact_del',
            'id'         => 'contact_del_123',
        ]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'success']);

        $this->assertDatabaseHas('webhook_logs', [
            'location_id' => 'loc_contact_del',
            'event_type'  => 'ContactDelete',
        ]);
    }

    public function test_loop_prevention_skips_appointment_webhook_with_wess_signature(): void
    {
        WessConfig::create([
            'location_id'     => 'loc_loop_appt',
            'api_token'       => 'test_token',
            'is_sync_enabled' => true,
        ]);

        $response = $this->postJson('/api/webhook', [
            'type'        => 'AppointmentCreate',
            'locationId'  => 'loc_loop_appt',
            'appointment' => [
                'id'        => 'appt_from_wess_123',
                'contactId' => 'contact_abc',
                'title'     => 'Facial Treatment (Booked Slot)',
                'notes'     => 'WESS Booking ID: 98765',
            ],
        ]);

        $response->assertStatus(200);

        $log = WebhookLog::where('location_id', 'loc_loop_appt')->latest()->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('Loop prevention', $log->response_body);
    }

    public function test_loop_prevention_skips_contact_webhook_when_cache_lock_present(): void
    {
        WessConfig::create([
            'location_id'     => 'loc_loop_contact',
            'api_token'       => 'test_token',
            'is_sync_enabled' => true,
        ]);

        \Illuminate\Support\Facades\Cache::put('wess_origin_contact_contact_loop_xyz', true, 300);

        $response = $this->postJson('/api/webhook', [
            'type'       => 'ContactUpdate',
            'locationId' => 'loc_loop_contact',
            'id'         => 'contact_loop_xyz',
            'phone'      => '+60123456789',
        ]);

        $response->assertStatus(200);

        $log = WebhookLog::where('location_id', 'loc_loop_contact')->latest()->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('Loop prevention', $log->response_body);
    }

    public function test_appointment_update_reschedules_in_wess_when_time_changed(): void
    {
        WessConfig::create([
            'location_id'     => 'loc_update_test',
            'api_token'       => 'test_token',
            'base_url'        => 'https://api.wessconnect.com/api/v1/online',
            'branch_id'       => 1,
            'is_sync_enabled' => true,
        ]);

        \App\Models\SyncedAppointment::create([
            'location_id'         => 'loc_update_test',
            'wess_appointment_id' => '28131209',
            'ghl_appointment_id'  => 'ghl_appt_resched_1',
            'status'              => 'confirmed',
            'start_time'          => '2026-10-26 20:45:00',
            'end_time'            => '2026-10-26 21:45:00',
        ]);

        $cancelledOldAppt = false;
        $bookedNewAppt    = false;
        $capturedNewDate  = null;

        Http::fake([
            'https://api.wessconnect.com/api/v1/online/customers/lookup/phone-number/*' => Http::response([
                [
                    'id'         => 4748191,
                    'first_name' => 'Sherry',
                    'last_name'  => 'Test',
                ],
            ], 200),

            'https://api.wessconnect.com/api/v1/online/branches/1/appointments/services' => Http::response([
                'data' => [
                    [
                        'id'   => 329401,
                        'code' => 'S01007',
                        'name' => 'MC Generic Service',
                    ],
                ],
            ], 200),

            'https://api.wessconnect.com/api/v1/online/branches/1/appointments/28131209/cancel' => function ($request) use (&$cancelledOldAppt) {
                $cancelledOldAppt = true;
                return Http::response(['success' => true], 200);
            },

            'https://api.wessconnect.com/api/v1/online/branches/1/appointments' => function ($request) use (&$bookedNewAppt, &$capturedNewDate) {
                $bookedNewAppt = true;
                $data = $request->data();
                $capturedNewDate = $data['date'] ?? null;
                return Http::response([
                    'data' => [
                        'id'     => 28131555,
                        'status' => 'Request',
                    ],
                ], 200);
            },
        ]);

        $response = $this->postJson('/api/webhook', [
            'type'        => 'AppointmentUpdate',
            'locationId'  => 'loc_update_test',
            'appointment' => [
                'id'                => 'ghl_appt_resched_1',
                'appointmentStatus' => 'confirmed',
                'startTime'         => '2026-10-26T11:15:00.000Z', // 19:15:00 Singapore Time
                'phone'             => '+6581234567',
                'firstName'         => 'Sherry',
                'lastName'          => 'Test',
            ],
        ]);

        $response->assertStatus(200);

        // Verify old booking was cancelled and new booking was made with new date
        $this->assertTrue($cancelledOldAppt, 'Expected old WESS appointment #28131209 to be cancelled');
        $this->assertTrue($bookedNewAppt, 'Expected new WESS appointment to be booked');
        $this->assertEquals('2026-10-26 19:15:00', $capturedNewDate);

        // Verify database records
        $this->assertDatabaseHas('synced_appointments', [
            'location_id'         => 'loc_update_test',
            'wess_appointment_id' => '28131555',
            'ghl_appointment_id'  => 'ghl_appt_resched_1',
            'start_time'          => '2026-10-26 19:15:00',
        ]);

        $this->assertDatabaseHas('synced_appointments', [
            'location_id'         => 'loc_update_test',
            'wess_appointment_id' => '28131209',
            'status'              => 'cancelled',
        ]);

        $log = WebhookLog::where('location_id', 'loc_update_test')->latest()->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('updated', $log->response_body);
        $this->assertStringContainsString('28131555', $log->response_body);
    }

    public function test_appointment_update_skips_when_no_details_changed(): void
    {
        WessConfig::create([
            'location_id'     => 'loc_noop_test',
            'api_token'       => 'test_token',
            'base_url'        => 'https://api.wessconnect.com/api/v1/online',
            'branch_id'       => 1,
            'is_sync_enabled' => true,
        ]);

        \App\Models\SyncedAppointment::create([
            'location_id'         => 'loc_noop_test',
            'wess_appointment_id' => '28131209',
            'ghl_appointment_id'  => 'ghl_appt_noop_1',
            'status'              => 'confirmed',
            'start_time'          => '2026-10-26 19:15:00',
            'end_time'            => '2026-10-26 19:30:00',
        ]);

        $calledCancelOrBook = false;

        Http::fake([
            'https://api.wessconnect.com/api/v1/online/customers/lookup/phone-number/*' => Http::response([
                [
                    'id'         => 4748191,
                    'first_name' => 'Sherry',
                    'last_name'  => 'Test',
                ],
            ], 200),

            'https://api.wessconnect.com/api/v1/online/branches/1/appointments/services' => Http::response([
                'data' => [
                    [
                        'id'   => 329401,
                        'code' => 'S01007',
                        'name' => 'MC Generic Service',
                    ],
                ],
            ], 200),

            'https://api.wessconnect.com/api/v1/online/branches/1/appointments*' => function ($request) use (&$calledCancelOrBook) {
                if ($request->method() === 'POST' || $request->method() === 'PATCH') {
                    $calledCancelOrBook = true;
                }
                return Http::response(['success' => true], 200);
            },
        ]);

        $response = $this->postJson('/api/webhook', [
            'type'        => 'AppointmentUpdate',
            'locationId'  => 'loc_noop_test',
            'appointment' => [
                'id'                => 'ghl_appt_noop_1',
                'appointmentStatus' => 'confirmed',
                'startTime'         => '2026-10-26T11:15:00.000Z', // evaluates to 2026-10-26 19:15:00 (identical)
                'phone'             => '+6581234567',
                'firstName'         => 'Sherry',
                'lastName'          => 'Test',
            ],
        ]);

        $response->assertStatus(200);

        $this->assertFalse($calledCancelOrBook, 'Expected neither cancellation nor booking for identical details');

        $log = WebhookLog::where('location_id', 'loc_noop_test')->latest()->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('skipped', $log->response_body);
        $this->assertStringContainsString('identical schedule details', $log->response_body);
    }

    public function test_appointment_update_does_not_cancel_when_ghl_status_differs_from_wess_request(): void
    {
        WessConfig::create([
            'location_id'     => 'loc_status_diff_test',
            'api_token'       => 'test_token',
            'base_url'        => 'https://api.wessconnect.com/api/v1/online',
            'branch_id'       => 1,
            'is_sync_enabled' => true,
        ]);

        // In WESS, the booking was created with status 'Request'
        $synced = \App\Models\SyncedAppointment::create([
            'location_id'         => 'loc_status_diff_test',
            'wess_appointment_id' => '28134588',
            'ghl_appointment_id'  => 'ghl_appt_diff_1',
            'status'              => 'Request',
            'start_time'          => '2026-10-26 14:45:00',
            'end_time'            => '2026-10-26 15:00:00',
        ]);

        $calledCancelOrBook = false;

        Http::fake([
            'https://api.wessconnect.com/api/v1/online/customers/lookup/phone-number/*' => Http::response([
                [
                    'id'         => 4748191,
                    'first_name' => 'Sherry',
                    'last_name'  => 'Test',
                ],
            ], 200),

            'https://api.wessconnect.com/api/v1/online/branches/1/appointments/services' => Http::response([
                'data' => [
                    [
                        'id'   => 329401,
                        'code' => 'S01007',
                        'name' => 'MC Generic Service',
                    ],
                ],
            ], 200),

            'https://api.wessconnect.com/api/v1/online/branches/1/appointments*' => function ($request) use (&$calledCancelOrBook) {
                if ($request->method() === 'POST' || $request->method() === 'PATCH') {
                    $calledCancelOrBook = true;
                }
                return Http::response(['success' => true], 200);
            },
        ]);

        // GHL sends an AppointmentUpdate with appointmentStatus = 'confirmed' for the same slot
        $response = $this->postJson('/api/webhook', [
            'type'        => 'AppointmentUpdate',
            'locationId'  => 'loc_status_diff_test',
            'appointment' => [
                'id'                => 'ghl_appt_diff_1',
                'appointmentStatus' => 'confirmed',
                'startTime'         => '2026-10-26T06:45:00.000Z', // 14:45:00 SG time (identical)
                'phone'             => '+6581234567',
                'firstName'         => 'Sherry',
                'lastName'          => 'Test',
            ],
        ]);

        $response->assertStatus(200);

        // Crucial Assertion: Must NEVER cancel or rebook in WESS when time has not changed!
        $this->assertFalse($calledCancelOrBook, 'Expected neither cancellation nor booking in WESS when time has not changed');

        // Local record status is updated to confirmed
        $this->assertEquals('confirmed', $synced->fresh()->status);

        $log = WebhookLog::where('location_id', 'loc_status_diff_test')->latest()->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('skipped', $log->response_body);
        $this->assertStringContainsString('identical schedule details', $log->response_body);
    }

    public function test_appointment_create_skips_when_slot_occupied_in_wess(): void
    {
        WessConfig::create([
            'location_id'     => 'loc_slot_conflict_test',
            'api_token'       => 'test_token',
            'base_url'        => 'https://api.wessconnect.com/api/v1/online',
            'branch_id'       => 1,
            'is_sync_enabled' => true,
        ]);

        $calledBook = false;

        Http::fake([
            'https://api.wessconnect.com/api/v1/online/customers/lookup/phone-number/*' => Http::response([
                [
                    'id'         => 4748191,
                    'first_name' => 'Sherry',
                    'last_name'  => 'Test',
                ],
            ], 200),

            'https://api.wessconnect.com/api/v1/online/branches/1/appointments/services' => Http::response([
                'data' => [
                    [
                        'id'   => 329401,
                        'code' => 'S01007',
                        'name' => 'MC Generic Service',
                    ],
                ],
            ], 200),

            'https://api.wessconnect.com/api/v1/online/branches/1/appointments/employees' => Http::response([
                'data' => [
                    [
                        'id'         => 10290,
                        'first_name' => 'Aarin',
                        'last_name'  => '',
                        'code'       => 'EP01009',
                    ],
                ],
            ], 200),

            'https://api.wessconnect.com/api/v1/online/branches/1/appointments*' => function ($request) use (&$calledBook) {
                if ($request->method() === 'POST') {
                    $calledBook = true;
                    return Http::response(['data' => ['id' => 999999, 'status' => 'Request']], 200);
                }

                // GET request for slot checking: Return existing overlapping appointment for Aarin
                return Http::response([
                    'data' => [
                        [
                            'id'              => 28098348,
                            'date'            => '2026-11-25T03:00:00.000000Z', // 11:00:00 SGT
                            'processing_time' => 60,
                            'status'          => 'Open',
                            'customer'        => [
                                'first_name' => 'Jolyn',
                                'last_name'  => 'Mo',
                            ],
                            'employees'       => [
                                [
                                    'id'         => 10290,
                                    'first_name' => 'Aarin',
                                ],
                            ],
                        ],
                    ],
                ], 200);
            },
        ]);

        // Attempt to book Aarin at 11:00:00 SGT (overlaps with existing appointment 11:00 - 12:00)
        $response = $this->postJson('/api/webhook', [
            'type'        => 'AppointmentCreate',
            'locationId'  => 'loc_slot_conflict_test',
            'appointment' => [
                'id'                => 'ghl_appt_conflict_1',
                'appointmentStatus' => 'confirmed',
                'startTime'         => '2026-11-25T03:00:00.000Z', // 11:00:00 SGT
                'endTime'           => '2026-11-25T04:00:00.000Z', // 12:00:00 SGT
                'phone'             => '+6581234567',
                'firstName'         => 'Sherry',
                'lastName'          => 'Test',
            ],
        ]);

        $response->assertStatus(200);

        // Booking POST must NEVER be called when slot is occupied
        $this->assertFalse($calledBook, 'Expected booking POST to WESS to be skipped due to slot conflict');

        $log = WebhookLog::where('location_id', 'loc_slot_conflict_test')->latest()->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('skipped', $log->response_body);
        $this->assertStringContainsString('Slot conflict detected', $log->response_body);
        $this->assertStringContainsString('28098348', $log->response_body);
    }

    public function test_appointment_create_books_when_slot_is_free_in_wess(): void
    {
        WessConfig::create([
            'location_id'     => 'loc_slot_free_test',
            'api_token'       => 'test_token',
            'base_url'        => 'https://api.wessconnect.com/api/v1/online',
            'branch_id'       => 1,
            'is_sync_enabled' => true,
        ]);

        $calledBook = false;

        Http::fake([
            'https://api.wessconnect.com/api/v1/online/customers/lookup/phone-number/*' => Http::response([
                [
                    'id'         => 4748191,
                    'first_name' => 'Sherry',
                    'last_name'  => 'Test',
                ],
            ], 200),

            'https://api.wessconnect.com/api/v1/online/branches/1/appointments/services' => Http::response([
                'data' => [
                    [
                        'id'   => 329401,
                        'code' => 'S01007',
                        'name' => 'MC Generic Service',
                    ],
                ],
            ], 200),

            'https://api.wessconnect.com/api/v1/online/branches/1/appointments/employees' => Http::response([
                'data' => [
                    [
                        'id'         => 10290,
                        'first_name' => 'Aarin',
                        'last_name'  => '',
                        'code'       => 'EP01009',
                    ],
                ],
            ], 200),

            'https://api.wessconnect.com/api/v1/online/branches/1/appointments*' => function ($request) use (&$calledBook) {
                if ($request->method() === 'POST') {
                    $calledBook = true;
                    return Http::response(['data' => ['id' => 888888, 'status' => 'Request']], 200);
                }

                // GET request for slot checking: Return empty appointments (slot is free)
                return Http::response(['data' => []], 200);
            },
        ]);

        $response = $this->postJson('/api/webhook', [
            'type'        => 'AppointmentCreate',
            'locationId'  => 'loc_slot_free_test',
            'appointment' => [
                'id'                => 'ghl_appt_free_1',
                'appointmentStatus' => 'confirmed',
                'startTime'         => '2026-11-25T03:00:00.000Z', // 11:00:00 SGT
                'endTime'           => '2026-11-25T04:00:00.000Z',
                'phone'             => '+6581234567',
                'firstName'         => 'Sherry',
                'lastName'          => 'Test',
            ],
        ]);

        $response->assertStatus(200);

        // Booking POST must be called when slot is free
        $this->assertTrue($calledBook, 'Expected appointment to be booked in WESS when slot is free');

        $this->assertDatabaseHas('synced_appointments', [
            'location_id'         => 'loc_slot_free_test',
            'wess_appointment_id' => '888888',
            'ghl_appointment_id'  => 'ghl_appt_free_1',
        ]);
    }

    public function test_customer_creation_sends_normalized_gender_to_wess(): void
    {
        WessConfig::create([
            'location_id'     => 'loc_create_gender_test',
            'api_token'       => 'test_token',
            'base_url'        => 'https://api.wessconnect.com/api/v1/online',
            'branch_id'       => 1,
            'is_sync_enabled' => true,
        ]);

        $capturedPayload = null;

        Http::fake([
            // Customer does not exist in WESS -> returns empty array
            'https://api.wessconnect.com/api/v1/online/customers/lookup/phone-number/*' => Http::response([], 200),

            // Customer creation endpoint
            'https://api.wessconnect.com/api/v1/online/branches/1/customers' => function ($request) use (&$capturedPayload) {
                $capturedPayload = $request->data();
                return Http::response([
                    'data' => [
                        'id'         => 9876,
                        'first_name' => 'Alice',
                        'last_name'  => 'Wong',
                        'code'       => 'C009876',
                        'customer_detail' => [
                            'gender' => 'F',
                        ],
                    ],
                ], 200);
            },
        ]);

        $response = $this->postJson('/api/webhook', [
            'type'       => 'ContactCreate',
            'locationId' => 'loc_create_gender_test',
            'contact'    => [
                'id'        => 'contact_gender_123',
                'firstName' => 'Alice',
                'lastName'  => 'Wong',
                'phone'     => '+6598765432',
                'gender'    => 'Female',
            ],
        ]);

        $response->assertStatus(200);

        $this->assertNotNull($capturedPayload, 'Expected createCustomer to be called');
        $this->assertEquals('Alice', $capturedPayload['first_name']);
        $this->assertEquals('Wong', $capturedPayload['last_name']);
        $this->assertEquals('65', $capturedPayload['phone_mobile_country_code']);
        $this->assertEquals('98765432', $capturedPayload['phone_mobile']);
        // Crucial: Gender must be normalized to 'F' under detail.gender per WESS API v1.0.0
        $this->assertEquals('F', $capturedPayload['detail']['gender'] ?? null);
    }

    public function test_contact_creation_maps_all_available_ghl_fields_to_wess(): void
    {
        WessConfig::create([
            'location_id'     => 'loc_full_fields_test',
            'api_token'       => 'test_token',
            'base_url'        => 'https://api.wessconnect.com/api/v1/online',
            'branch_id'       => 1,
            'is_sync_enabled' => true,
        ]);

        $capturedPayload = null;

        Http::fake([
            // Customer does not exist in WESS -> empty array
            'https://api.wessconnect.com/api/v1/online/customers/lookup/phone-number/*' => Http::response([], 200),

            // Capture customer creation request
            'https://api.wessconnect.com/api/v1/online/branches/1/customers' => function ($request) use (&$capturedPayload) {
                $capturedPayload = $request->data();
                return Http::response([
                    'data' => [
                        'id'         => 106,
                        'first_name' => 'Alice',
                        'last_name'  => 'Wong',
                        'code'       => 'CUST-00106',
                        'customer_detail' => [
                            'gender' => 'F',
                            'email'  => 'alice.wong@example.com',
                        ],
                    ],
                ], 200);
            },
        ]);

        $response = $this->postJson('/api/webhook', [
            'type'       => 'ContactCreate',
            'locationId' => 'loc_full_fields_test',
            'contact'    => [
                'id'          => 'contact_full_123',
                'firstName'   => 'Alice',
                'lastName'    => 'Wong',
                'phone'       => '+6598765432',
                'email'       => 'alice.wong@example.com',
                'salutation'  => 'Ms',
                'dateOfBirth' => '1992-05-15',
                'address1'    => '10 Orchard Road, #05-12',
                'city'        => 'Singapore',
                'state'       => 'Singapore',
                'country'     => 'Singapore',
                'postalCode'  => '238888',
                'gender'      => 'Female',
                'customFields' => [
                    ['name' => 'NRIC', 'value' => 'S9205151A'],
                    ['key' => 'nationality', 'value' => 'Singapore'],
                    ['name' => 'Marital Status', 'value' => 'Single'],
                    ['name' => 'Occupation', 'value' => 'Engineer'],
                ],
            ],
        ]);

        $response->assertStatus(200);

        $this->assertNotNull($capturedPayload, 'Expected createCustomer to be called with full payload');
        // Root fields
        $this->assertEquals('Alice', $capturedPayload['first_name']);
        $this->assertEquals('Wong', $capturedPayload['last_name']);
        $this->assertEquals('65', $capturedPayload['phone_mobile_country_code']);
        $this->assertEquals('98765432', $capturedPayload['phone_mobile']);
        $this->assertEquals('alice.wong@example.com', $capturedPayload['email']);
        $this->assertEquals('MS', $capturedPayload['salutation']);
        $this->assertEquals('S9205151A', $capturedPayload['ic_no']);

        // Nested detail fields per WESS API v1.0.0 Section 3.5
        $this->assertIsArray($capturedPayload['detail']);
        $this->assertEquals('F', $capturedPayload['detail']['gender']);
        $this->assertEquals('alice.wong@example.com', $capturedPayload['detail']['email']);
        $this->assertEquals(1992, $capturedPayload['detail']['birthday_year']);
        $this->assertEquals(5, $capturedPayload['detail']['birthday_month']);
        $this->assertEquals(15, $capturedPayload['detail']['birthday_day']);
        $this->assertEquals('10 Orchard Road, #05-12', $capturedPayload['detail']['address_street']);
        $this->assertEquals('Singapore', $capturedPayload['detail']['address_city']);
        $this->assertEquals('Singapore', $capturedPayload['detail']['address_state']);
        $this->assertEquals('Singapore', $capturedPayload['detail']['address_country']);
        $this->assertEquals('238888', $capturedPayload['detail']['address_postcode']);
        $this->assertEquals('Singapore', $capturedPayload['detail']['nationality']);
        $this->assertEquals('S', $capturedPayload['detail']['marital_status']);
        $this->assertEquals('Engineer', $capturedPayload['detail']['occupation']);
    }

    public function test_appointment_webhook_skips_customer_creation_when_contact_has_wess_code(): void
    {
        WessConfig::create([
            'location_id'     => 'loc_wess_code_check',
            'api_token'       => 'test_token',
            'base_url'        => 'https://api.wessconnect.com/api/v1/online',
            'branch_id'       => 1,
            'is_sync_enabled' => true,
        ]);

        $customerCreationAttempted = false;

        Http::fake([
            // Customer creation endpoint must NEVER be called
            'https://api.wessconnect.com/api/v1/online/branches/1/customers' => function () use (&$customerCreationAttempted) {
                $customerCreationAttempted = true;
                return Http::response(['id' => 999], 200);
            },
            // Lookup by phone returns existing customer
            'https://api.wessconnect.com/api/v1/online/customers/lookup/phone-number/*' => Http::response([
                [
                    'id'         => 8116,
                    'code'       => 'C006081',
                    'first_name' => 'Existing',
                    'last_name'  => 'Member',
                    'phone_mobile' => '6586121073',
                ]
            ], 200),
            // Existing customer details by ID
            'https://api.wessconnect.com/api/v1/online/branches/1/customers/8116' => Http::response([
                'id'         => 8116,
                'code'       => 'C006081',
                'first_name' => 'Existing',
                'last_name'  => 'Member',
            ], 200),
            // Appointments list for slot check
            'https://api.wessconnect.com/api/v1/online/branches/1/appointments*' => Http::response([], 200),
            // Appointment creation in WESS
            'https://api.wessconnect.com/api/v1/online/branches/1/appointments' => Http::response([
                'id'          => 55001,
                'customer_id' => 8116,
                'status'      => 'Confirmed',
            ], 200),
            // GHL API calls
            'https://services.leadconnectorhq.com/*' => Http::response([
                'customFields' => [
                    ['id' => 'f_wess', 'name' => 'WESS Code'],
                    ['id' => 'f_visit', 'name' => 'Last Visit'],
                ],
                'contact' => ['id' => 'contact_wess_123'],
            ], 200),
        ]);

        $response = $this->postJson('/api/webhook', [
            'type'        => 'AppointmentCreate',
            'locationId'  => 'loc_wess_code_check',
            'appointment' => [
                'id'          => 'appt_with_wess_code',
                'contactId'   => 'contact_wess_123',
                'startTime'   => '2026-11-01 10:00:00',
                'endTime'     => '2026-11-01 11:00:00',
                'appointmentStatus' => 'confirmed',
                'contact'     => [
                    'id'          => 'contact_wess_123',
                    'phone'       => '+6586121073',
                    'firstName'   => 'Existing',
                    'lastName'    => 'Member',
                    'customFields' => [
                        ['name' => 'WESS Code', 'value' => 'C006081'],
                    ],
                ],
            ],
        ]);

        $response->assertStatus(200);
        $this->assertFalse($customerCreationAttempted, 'Customer creation was called despite contact having WESS Code!');
    }

    public function test_appointment_webhook_skips_customer_creation_when_phone_matches_existing_customer(): void
    {
        WessConfig::create([
            'location_id'     => 'loc_phone_check',
            'api_token'       => 'test_token',
            'base_url'        => 'https://api.wessconnect.com/api/v1/online',
            'branch_id'       => 1,
            'is_sync_enabled' => true,
        ]);

        $customerCreationAttempted = false;

        Http::fake([
            // Customer creation endpoint must NEVER be called
            'https://api.wessconnect.com/api/v1/online/branches/1/customers' => function () use (&$customerCreationAttempted) {
                $customerCreationAttempted = true;
                return Http::response(['id' => 999], 200);
            },
            // Lookup by phone finds existing customer
            'https://api.wessconnect.com/api/v1/online/customers/lookup/phone-number/*' => Http::response([
                [
                    'id'           => 8116,
                    'code'         => 'C006081',
                    'first_name'   => 'HX',
                    'last_name'    => 'Chen',
                    'phone_mobile' => '6586121073',
                ]
            ], 200),
            // Slot availability
            'https://api.wessconnect.com/api/v1/online/branches/1/appointments*' => Http::response([], 200),
            // Appointment creation in WESS
            'https://api.wessconnect.com/api/v1/online/branches/1/appointments' => Http::response([
                'id'          => 55002,
                'customer_id' => 8116,
                'status'      => 'Confirmed',
            ], 200),
            // GHL API
            'https://services.leadconnectorhq.com/*' => Http::response([
                'customFields' => [
                    ['id' => 'f_wess', 'name' => 'WESS Code'],
                    ['id' => 'f_visit', 'name' => 'Last Visit'],
                ],
                'contact' => ['id' => 'contact_phone_123'],
            ], 200),
        ]);

        $response = $this->postJson('/api/webhook', [
            'type'        => 'AppointmentCreate',
            'locationId'  => 'loc_phone_check',
            'appointment' => [
                'id'          => 'appt_phone_match',
                'contactId'   => 'contact_phone_123',
                'startTime'   => '2026-11-01 14:00:00',
                'endTime'     => '2026-11-01 15:00:00',
                'appointmentStatus' => 'confirmed',
                'contact'     => [
                    'id'        => 'contact_phone_123',
                    'phone'     => '+6586121073',
                    'firstName' => 'HX',
                    'lastName'  => 'Chen',
                    // No customFields provided -> triggers Check 2 (phone lookup)
                ],
            ],
        ]);

        $response->assertStatus(200);
        $this->assertFalse($customerCreationAttempted, 'Customer creation was called despite phone matching existing customer!');
    }

    public function test_contact_webhook_skips_customer_creation_when_contact_has_wess_code(): void
    {
        WessConfig::create([
            'location_id'     => 'loc_contact_wess_code',
            'api_token'       => 'test_token',
            'base_url'        => 'https://api.wessconnect.com/api/v1/online',
            'branch_id'       => 1,
            'is_sync_enabled' => true,
        ]);

        $customerCreationAttempted = false;

        Http::fake([
            // Customer creation endpoint must NEVER be called
            'https://api.wessconnect.com/api/v1/online/branches/1/customers' => function () use (&$customerCreationAttempted) {
                $customerCreationAttempted = true;
                return Http::response(['id' => 999], 200);
            },
            // Lookup by phone finds customer
            'https://api.wessconnect.com/api/v1/online/customers/lookup/phone-number/*' => Http::response([
                [
                    'id'           => 8116,
                    'code'         => 'C006081',
                    'first_name'   => 'Existing',
                    'last_name'    => 'Member',
                    'phone_mobile' => '6586121073',
                ]
            ], 200),
            // GHL API
            'https://services.leadconnectorhq.com/*' => Http::response([], 200),
        ]);

        $response = $this->postJson('/api/webhook', [
            'type'       => 'ContactCreate',
            'locationId' => 'loc_contact_wess_code',
            'contact'    => [
                'id'        => 'contact_wess_code_456',
                'phone'     => '+6586121073',
                'firstName' => 'Existing',
                'lastName'  => 'Member',
                'customFields' => [
                    ['name' => 'WESS Code', 'value' => 'C006081'],
                ],
            ],
        ]);

        $response->assertStatus(200);
        $this->assertFalse($customerCreationAttempted, 'Customer creation was called despite contact having WESS Code in Contact webhook!');
    }

    public function test_contact_webhook_skips_customer_creation_when_phone_matches_existing_customer(): void
    {
        WessConfig::create([
            'location_id'     => 'loc_contact_phone_match',
            'api_token'       => 'test_token',
            'base_url'        => 'https://api.wessconnect.com/api/v1/online',
            'branch_id'       => 1,
            'is_sync_enabled' => true,
        ]);

        $customerCreationAttempted = false;

        Http::fake([
            // Customer creation endpoint must NEVER be called
            'https://api.wessconnect.com/api/v1/online/branches/1/customers' => function () use (&$customerCreationAttempted) {
                $customerCreationAttempted = true;
                return Http::response(['id' => 999], 200);
            },
            // Lookup by phone returns existing customer
            'https://api.wessconnect.com/api/v1/online/customers/lookup/phone-number/*' => Http::response([
                [
                    'id'           => 8116,
                    'code'         => 'CS00004-1',
                    'first_name'   => 'HX',
                    'last_name'    => 'Chen',
                    'phone_mobile' => '6586121073',
                ]
            ], 200),
            // GHL API
            'https://services.leadconnectorhq.com/*' => Http::response([], 200),
        ]);

        $response = $this->postJson('/api/webhook', [
            'type'       => 'ContactCreate',
            'locationId' => 'loc_contact_phone_match',
            'contact'    => [
                'id'        => 'contact_no_code_456',
                'phone'     => '86121073', // Local 8-digit format without +65
                'firstName' => 'HX',
                'lastName'  => 'Chen',
                // No WESS Code
            ],
        ]);

        $response->assertStatus(200);
        $this->assertFalse($customerCreationAttempted, 'Customer creation was called despite phone matching existing customer in Contact webhook!');
    }

    public function test_contact_webhook_skips_when_name_is_missing(): void
    {
        WessConfig::create([
            'location_id'     => 'loc_skip_no_name',
            'api_token'       => 'test_token',
            'base_url'        => 'https://api.wessconnect.com/api/v1/online',
            'branch_id'       => 1,
            'is_sync_enabled' => true,
        ]);

        $customerCreationAttempted = false;

        Http::fake([
            'https://api.wessconnect.com/api/v1/online/branches/1/customers' => function () use (&$customerCreationAttempted) {
                $customerCreationAttempted = true;
                return Http::response(['id' => 999], 200);
            },
            'https://services.leadconnectorhq.com/*' => Http::response([], 200),
        ]);

        $response = $this->postJson('/api/webhook', [
            'type'       => 'ContactCreate',
            'locationId' => 'loc_skip_no_name',
            'contact'    => [
                'id'        => 'contact_chat_no_name',
                'phone'     => '+6591234567',
                'firstName' => '',
                'lastName'  => '',
                'name'      => '',
            ],
        ]);

        $response->assertStatus(200);
        $this->assertFalse($customerCreationAttempted, 'Customer creation was attempted for a contact with NO name!');
    }

    public function test_contact_webhook_skips_when_name_is_only_phone_digits(): void
    {
        WessConfig::create([
            'location_id'     => 'loc_skip_phone_as_name',
            'api_token'       => 'test_token',
            'base_url'        => 'https://api.wessconnect.com/api/v1/online',
            'branch_id'       => 1,
            'is_sync_enabled' => true,
        ]);

        $customerCreationAttempted = false;

        Http::fake([
            'https://api.wessconnect.com/api/v1/online/branches/1/customers' => function () use (&$customerCreationAttempted) {
                $customerCreationAttempted = true;
                return Http::response(['id' => 999], 200);
            },
            'https://services.leadconnectorhq.com/*' => Http::response([], 200),
        ]);

        $response = $this->postJson('/api/webhook', [
            'type'       => 'ContactCreate',
            'locationId' => 'loc_skip_phone_as_name',
            'contact'    => [
                'id'        => 'contact_chat_phone_name',
                'phone'     => '+6591234567',
                'name'      => '+65 9123 4567', // Raw phone string as name from conversation trigger
            ],
        ]);

        $response->assertStatus(200);
        $this->assertFalse($customerCreationAttempted, 'Customer creation was attempted for a contact whose name is just phone digits!');
    }

    public function test_contact_webhook_skips_when_phone_is_missing(): void
    {
        WessConfig::create([
            'location_id'     => 'loc_skip_no_phone',
            'api_token'       => 'test_token',
            'base_url'        => 'https://api.wessconnect.com/api/v1/online',
            'branch_id'       => 1,
            'is_sync_enabled' => true,
        ]);

        $customerCreationAttempted = false;

        Http::fake([
            'https://api.wessconnect.com/api/v1/online/branches/1/customers' => function () use (&$customerCreationAttempted) {
                $customerCreationAttempted = true;
                return Http::response(['id' => 999], 200);
            },
            'https://services.leadconnectorhq.com/*' => Http::response([], 200),
        ]);

        $response = $this->postJson('/api/webhook', [
            'type'       => 'ContactCreate',
            'locationId' => 'loc_skip_no_phone',
            'contact'    => [
                'id'        => 'contact_no_phone_lead',
                'phone'     => '',
                'firstName' => 'John',
                'lastName'  => 'Tan',
            ],
        ]);

        $response->assertStatus(200);
        $this->assertFalse($customerCreationAttempted, 'Customer creation was attempted for a contact with NO phone!');
    }

    public function test_appointment_webhook_skips_customer_creation_when_name_is_missing(): void
    {
        WessConfig::create([
            'location_id'     => 'loc_skip_appt_no_name',
            'api_token'       => 'test_token',
            'base_url'        => 'https://api.wessconnect.com/api/v1/online',
            'branch_id'       => 1,
            'is_sync_enabled' => true,
        ]);

        $customerCreationAttempted = false;

        Http::fake([
            // Customer creation endpoint must NEVER be called
            'https://api.wessconnect.com/api/v1/online/branches/1/customers' => function () use (&$customerCreationAttempted) {
                $customerCreationAttempted = true;
                return Http::response(['id' => 999], 200);
            },
            // Lookup by phone finds no existing customer
            'https://api.wessconnect.com/api/v1/online/customers/lookup/phone-number/*' => Http::response([], 200),
            // GHL API
            'https://services.leadconnectorhq.com/*' => Http::response([
                'customFields' => [],
                'contact'      => ['id' => 'contact_no_name_appt'],
            ], 200),
        ]);

        $response = $this->postJson('/api/webhook', [
            'type'        => 'AppointmentCreate',
            'locationId'  => 'loc_skip_appt_no_name',
            'appointment' => [
                'id'          => 'appt_no_name_123',
                'contactId'   => 'contact_no_name_appt',
                'startTime'   => '2026-11-01 10:00:00',
                'endTime'     => '2026-11-01 11:00:00',
                'appointmentStatus' => 'confirmed',
                'contact'     => [
                    'id'        => 'contact_no_name_appt',
                    'phone'     => '+6591234567',
                    'firstName' => '',
                    'lastName'  => '',
                    // No name provided
                ],
            ],
        ]);

        $response->assertStatus(200);
        $this->assertFalse($customerCreationAttempted, 'Customer creation was called for an appointment with NO customer name!');
    }
}


