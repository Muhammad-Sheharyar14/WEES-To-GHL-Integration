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
}

