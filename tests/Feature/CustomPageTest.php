<?php

namespace Tests\Feature;

use App\Models\GhlToken;
use App\Models\WessConfig;
use App\Models\WebhookLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_custom_page_renders(): void
    {
        $response = $this->get('/custom-page');
        $response->assertStatus(200);
        $response->assertSee('WESS Integration');
        $response->assertSee('Activity & Webhook Logs', false);
        $response->assertSee('REQUEST_USER_DATA');
    }

    public function test_get_credentials_endpoint_returns_data_for_location(): void
    {
        WessConfig::create([
            'location_id'     => 'loc_ghl_999',
            'company_id'      => 'comp_ghl_111',
            'api_token'       => 'test_wess_token_xyz',
            'base_url'        => 'https://api.prelive.wessconnect.net/api/v1/online',
            'branch_id'       => 1,
            'branch_name'     => 'Branch1',
            'is_sync_enabled' => true,
            'is_connected'    => true,
        ]);

        $response = $this->postJson('/api/custom-page/get', [
            'locationId' => 'loc_ghl_999',
            'companyId'  => 'comp_ghl_111',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success'         => true,
                'is_verified'     => true,
                'is_sync_enabled' => true,
                'credentials'     => [
                    'api_token'       => 'test_wess_token_xyz',
                    'branch_id'       => '1',
                    'branch_name'     => 'Branch1',
                    'is_sync_enabled' => true,
                    'is_connected'    => true,
                ],
            ]);
    }

    public function test_toggle_sync_endpoint(): void
    {
        WessConfig::create([
            'location_id'     => 'loc_ghl_999',
            'api_token'       => 'token_123',
            'is_sync_enabled' => true,
            'is_connected'    => true,
        ]);

        // Pause sync
        $response = $this->postJson('/api/custom-page/toggle-sync', [
            'locationId'      => 'loc_ghl_999',
            'is_sync_enabled' => false,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success'         => true,
                'is_sync_enabled' => false,
            ]);

        $this->assertDatabaseHas('wess_configs', [
            'location_id'     => 'loc_ghl_999',
            'is_sync_enabled' => false,
        ]);
    }

    public function test_get_logs_endpoint_paginates_10_per_page(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            WebhookLog::create([
                'source'          => 'ghl_webhook',
                'location_id'     => 'loc_test_logs',
                'event_type'      => 'AppointmentCreate',
                'endpoint'        => "https://api.prelive.wessconnect.net/api/v1/online/branches/1/appointments/{$i}",
                'method'          => 'POST',
                'payload'         => ['appointmentId' => $i],
                'response_status' => 200,
                'response_body'   => json_encode(['success' => true, 'id' => $i]),
            ]);
        }

        $response = $this->postJson('/api/custom-page/logs', [
            'locationId' => 'loc_test_logs',
            'page'       => 1,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'logs'    => [
                    'current_page' => 1,
                    'per_page'     => 10,
                    'total'        => 15,
                ],
            ]);

        $data = $response->json('logs.data');
        $this->assertCount(10, $data);
        $this->assertEquals('AppointmentCreate', $data[0]['event_type']);
        $this->assertEquals('POST', $data[0]['method']);
    }

    public function test_ghl_uninstall_webhook_cleans_up_credentials_tokens_and_logs(): void
    {
        WessConfig::create([
            'location_id' => 'loc_to_uninstall',
            'api_token'   => 'secret_wess_token',
        ]);

        GhlToken::create([
            'location_id'   => 'loc_to_uninstall',
            'access_token'  => 'ghl_access_token',
            'refresh_token' => 'ghl_refresh_token',
            'user_type'     => 'Location',
            'expires_at'    => now()->addHours(1),
            'is_active'     => true,
        ]);

        WebhookLog::create([
            'source'          => 'ghl_webhook',
            'location_id'     => 'loc_to_uninstall',
            'event_type'      => 'AppointmentCreate',
            'response_status' => 200,
        ]);

        $response = $this->postJson('/api/webhook', [
            'type'       => 'AppUninstall',
            'locationId' => 'loc_to_uninstall',
        ]);

        $response->assertStatus(200);

        // Verify credentials and logs were deleted
        $this->assertDatabaseMissing('wess_configs', ['location_id' => 'loc_to_uninstall']);
        $this->assertDatabaseMissing('ghl_tokens', ['location_id' => 'loc_to_uninstall']);
        $this->assertDatabaseMissing('webhook_logs', ['location_id' => 'loc_to_uninstall']);
    }

    public function test_save_credentials_saves_calendar_and_user_id(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            'https://api.prelive.wessconnect.net/*' => \Illuminate\Support\Facades\Http::response([
                'user' => ['id' => 1, 'name' => 'WESS Test User'],
                'data' => [['id' => 1, 'name' => 'Main Salon']],
                'permissions' => ['appointments.manage']
            ], 200),
            'https://services.leadconnectorhq.com/calendars*' => \Illuminate\Support\Facades\Http::response([
                'calendars' => [
                    [
                        'id'           => 'cal_ghl_booking_456',
                        'name'         => 'Salon Main Calendar',
                        'calendarType' => 'round_robin',
                        'teamMembers'  => [
                            ['userId' => 'user_stylist_789', 'priority' => 1, 'isPrimary' => true]
                        ]
                    ]
                ]
            ], 200),
        ]);

        $response = $this->postJson('/api/custom-page/save', [
            'locationId'       => 'loc_cal_test_123',
            'api_token'        => 'valid_token_xyz',
            'base_url'         => 'https://api.prelive.wessconnect.net/api/v1/online',
            'branch_id'        => '1',
            'branch_name'      => 'Main Salon',
            'calendar_id'      => 'cal_ghl_booking_456',
            'calendar_name'    => 'Salon Main Calendar',
            'calendar_user_id' => 'user_stylist_789',
            'is_sync_enabled'  => true,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success'     => true,
                'is_verified' => true,
            ]);

        $this->assertDatabaseHas('wess_configs', [
            'location_id'      => 'loc_cal_test_123',
            'calendar_id'      => 'cal_ghl_booking_456',
            'calendar_name'    => 'Salon Main Calendar',
            'calendar_user_id' => 'user_stylist_789',
        ]);
    }

    public function test_get_calendars_endpoint_returns_calendars(): void
    {
        $locationId = 'loc_cal_endpoint_test';

        GhlToken::create([
            'location_id'   => $locationId,
            'access_token'  => 'fake_ghl_token',
            'refresh_token' => 'fake_refresh_token',
            'user_type'     => 'Location',
            'expires_at'    => now()->addDay(),
        ]);

        \Illuminate\Support\Facades\Http::fake([
            'https://services.leadconnectorhq.com/calendars*' => \Illuminate\Support\Facades\Http::response([
                'calendars' => [
                    [
                        'id'           => 'cal_vip_1',
                        'name'         => 'VIP Spa Calendar',
                        'calendarType' => 'round_robin',
                        'teamMembers'  => [
                            ['userId' => 'usr_therapist_10', 'priority' => 1, 'isPrimary' => true]
                        ]
                    ]
                ]
            ], 200),
        ]);

        $response = $this->postJson('/api/custom-page/calendars', [
            'locationId' => $locationId,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success'   => true,
                'calendars' => [
                    [
                        'id'      => 'cal_vip_1',
                        'name'    => 'VIP Spa Calendar',
                        'user_id' => 'usr_therapist_10',
                    ]
                ]
            ]);
    }
}
