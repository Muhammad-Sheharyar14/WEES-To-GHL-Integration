<?php

namespace Tests\Feature;

use App\Models\GhlToken;
use App\Services\Ghl\GhlAuthService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GhlAuthServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_valid_access_token_returns_existing_token_if_valid(): void
    {
        $ghlToken = GhlToken::create([
            'location_id'   => 'loc_active_123',
            'user_type'     => 'Location',
            'access_token'  => 'valid_access_token_abc',
            'refresh_token' => 'refresh_token_xyz',
            'expires_at'    => Carbon::now()->addHours(12),
            'is_active'     => true,
        ]);

        $authService = app(GhlAuthService::class);
        $token = $authService->getValidAccessToken('loc_active_123');

        $this->assertEquals('valid_access_token_abc', $token);
    }

    public function test_get_valid_access_token_auto_refreshes_when_expiring_soon(): void
    {
        Http::fake([
            'https://services.leadconnectorhq.com/oauth/token' => Http::response([
                'access_token'  => 'new_fresh_access_token_999',
                'refresh_token' => 'new_fresh_refresh_token_888',
                'expires_in'    => 86400,
                'userType'      => 'Location',
                'locationId'    => 'loc_expiring_123',
            ], 200),
        ]);

        // Token expiring in 2 minutes (< 5 min buffer)
        $ghlToken = GhlToken::create([
            'location_id'   => 'loc_expiring_123',
            'user_type'     => 'Location',
            'access_token'  => 'old_expired_token',
            'refresh_token' => 'old_refresh_token',
            'expires_at'    => Carbon::now()->addMinutes(2),
            'is_active'     => true,
        ]);

        $authService = app(GhlAuthService::class);
        $token = $authService->getValidAccessToken('loc_expiring_123');

        $this->assertEquals('new_fresh_access_token_999', $token);

        $fresh = $ghlToken->fresh();
        $this->assertEquals('new_fresh_access_token_999', $fresh->access_token);
        $this->assertEquals('new_fresh_refresh_token_888', $fresh->refresh_token);
    }

    public function test_ensure_location_token_generates_from_agency_token(): void
    {
        // 1. Create active Agency Token
        GhlToken::create([
            'company_id'    => 'comp_agency_123',
            'user_type'     => 'Company',
            'access_token'  => 'agency_access_token_abc',
            'refresh_token' => 'agency_refresh_token_def',
            'expires_at'    => Carbon::now()->addDays(2),
            'is_active'     => true,
        ]);

        // 2. Fake GHL locationToken endpoint
        Http::fake([
            'https://services.leadconnectorhq.com/oauth/locationToken' => Http::response([
                'access_token'  => 'new_location_token_from_agency',
                'refresh_token' => 'new_location_refresh_token',
                'expires_in'    => 86400,
                'locationId'    => 'loc_subaccount_456',
                'companyId'     => 'comp_agency_123',
            ], 200),
        ]);

        $authService = app(GhlAuthService::class);
        $locationToken = $authService->ensureLocationToken('loc_subaccount_456', 'comp_agency_123');

        $this->assertNotNull($locationToken);
        $this->assertEquals('new_location_token_from_agency', $locationToken->access_token);
        $this->assertEquals('loc_subaccount_456', $locationToken->location_id);
    }
}
