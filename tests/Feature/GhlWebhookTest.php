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
}
