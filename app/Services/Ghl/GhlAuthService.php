<?php

namespace App\Services\Ghl;

use App\Models\GhlToken;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class GhlAuthService
{
    protected string $clientId;
    protected string $clientSecret;
    protected string $redirectUri;
    protected string $versionId;
    protected string $scopes;
    protected string $baseUrl;
    protected string $marketplaceUrl;

    public function __construct()
    {
        $this->clientId       = config('ghl.client_id', '');
        $this->clientSecret   = config('ghl.client_secret', '');
        $this->redirectUri    = config('ghl.redirect_uri', 'http://localhost:8000/callback');
        $this->versionId      = config('ghl.version_id', '');
        $this->scopes         = config('ghl.scopes', 'locations.readonly contacts.readonly contacts.write workflows.readonly');
        $this->baseUrl        = rtrim(config('ghl.base_url', 'https://services.leadconnectorhq.com'), '/');
        $this->marketplaceUrl = rtrim(config('ghl.marketplace_url', 'https://marketplace.leadconnectorhq.com'), '/');
    }

    /**
     * Generate the GHL OAuth installation / authorization URL
     */
    public function getAuthorizationUrl(): string
    {
        $params = [
            'response_type' => 'code',
            'redirect_uri'  => $this->redirectUri,
            'client_id'     => $this->clientId,
            'scope'         => $this->scopes,
            'version_id'    => $this->versionId,
        ];

        return "{$this->marketplaceUrl}/oauth/chooselocation?" . http_build_query($params);
    }

    /**
     * Exchange authorization code for GHL access/refresh token
     * Handles both Agency (Company) and Location OAuth
     */
    public function exchangeCode(string $code, string $userType = 'Location'): GhlToken
    {
        $response = Http::asForm()->post("{$this->baseUrl}/oauth/token", [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $this->redirectUri,
            'user_type'     => $userType,
        ]);

        // Accept both 200 and 201 as valid per GHL API specs
        if (!in_array($response->status(), [200, 201])) {
            Log::error('GHL OAuth Code Exchange Failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new Exception('Failed to exchange authorization code with GoHighLevel: ' . $response->body());
        }

        $data = $response->json();
        return $this->storeToken($data);
    }

    /**
     * Ensure a valid location token exists.
     * 1. If location token already exists: check expiry & refresh if needed.
     * 2. If no location token exists: check if an Agency token exists for this company.
     *    If agency token exists: generate location token via GHL API.
     */
    public function ensureLocationToken(string $locationId, ?string $companyId = null): ?GhlToken
    {
        // 1. Check existing token for this location
        $ghlToken = GhlToken::where('location_id', $locationId)
            ->where('is_active', true)
            ->latest()
            ->first();

        if ($ghlToken) {
            if ($ghlToken->isExpired(300)) {
                $ghlToken = $this->refreshToken($ghlToken);
            }
            return $ghlToken;
        }

        // 2. If no location token, check if Agency token exists
        $agencyToken = null;
        if ($companyId) {
            $agencyToken = GhlToken::where('company_id', $companyId)
                ->where('user_type', 'Company')
                ->where('is_active', true)
                ->latest()
                ->first();
        }

        // Fallback: search for any active agency token if companyId wasn't provided or exact match not found
        if (!$agencyToken) {
            $agencyToken = GhlToken::where('user_type', 'Company')
                ->where('is_active', true)
                ->latest()
                ->first();
        }

        if ($agencyToken) {
            try {
                $resolvedCompanyId = $companyId ?: $agencyToken->company_id;
                return $this->generateLocationTokenFromAgency($resolvedCompanyId, $locationId);
            } catch (Exception $e) {
                Log::warning("Could not generate location token from agency token for location {$locationId}: " . $e->getMessage());
            }
        }

        return null;
    }

    /**
     * Generate a Location Token from an Agency (Company) Token via GHL API
     */
    public function generateLocationTokenFromAgency(string $companyId, string $locationId): GhlToken
    {
        $companyAccessToken = $this->getValidCompanyToken($companyId);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $companyAccessToken,
            'Version'       => '2021-07-28',
            'Accept'        => 'application/json',
        ])->asForm()->post("{$this->baseUrl}/oauth/locationToken", [
            'companyId'  => $companyId,
            'locationId' => $locationId,
        ]);

        if (!in_array($response->status(), [200, 201])) {
            Log::error('GHL Generate Location Token from Agency Failed', [
                'status'      => $response->status(),
                'company_id'  => $companyId,
                'location_id' => $locationId,
                'body'        => $response->body(),
            ]);
            throw new Exception('Failed to generate location token from agency: ' . $response->body());
        }

        $data = $response->json();
        $data['locationId'] = $locationId;
        $data['companyId']  = $companyId;
        $data['userType']   = 'Location';

        return $this->storeToken($data);
    }

    /**
     * Get a guaranteed valid access token for a location (auto-refreshing or generating from agency if needed)
     */
    public function getValidAccessToken(string $locationId, ?string $companyId = null): string
    {
        $token = $this->ensureLocationToken($locationId, $companyId);

        if (!$token) {
            throw new Exception("No active GHL token found for location: {$locationId}. Please authenticate the App.");
        }

        return $token->access_token;
    }

    /**
     * Get a guaranteed valid access token for a company/agency
     */
    public function getValidCompanyToken(string $companyId): string
    {
        $ghlToken = GhlToken::where('company_id', $companyId)
            ->where('is_active', true)
            ->latest()
            ->first();

        if (!$ghlToken) {
            throw new Exception("No active GHL token found for company: {$companyId}");
        }

        if ($ghlToken->isExpired(300)) {
            $ghlToken = $this->refreshToken($ghlToken);
        }

        return $ghlToken->access_token;
    }

    /**
     * Refresh an existing token and persist the new credentials in the database
     */
    public function refreshToken(GhlToken $ghlToken): GhlToken
    {
        $response = Http::asForm()->post("{$this->baseUrl}/oauth/token", [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type'    => 'refresh_token',
            'refresh_token' => $ghlToken->refresh_token,
            'user_type'     => $ghlToken->user_type ?? 'Location',
            'redirect_uri'  => $this->redirectUri,
        ]);

        if (!in_array($response->status(), [200, 201])) {
            Log::error('GHL Token Refresh Failed', [
                'token_id'    => $ghlToken->id,
                'location_id' => $ghlToken->location_id,
                'status'      => $response->status(),
                'body'        => $response->body(),
            ]);
            throw new Exception('Failed to refresh GoHighLevel token: ' . $response->body());
        }

        $data = $response->json();
        return $this->updateTokenRecord($ghlToken, $data);
    }

    /**
     * Store a newly issued token payload
     */
    protected function storeToken(array $data): GhlToken
    {
        $expiresIn = $data['expires_in'] ?? 86400;
        $expiresAt = Carbon::now()->addSeconds($expiresIn);

        $locationId = $data['locationId'] ?? null;
        $companyId  = $data['companyId'] ?? null;
        $userType   = $data['userType'] ?? ($locationId ? 'Location' : 'Company');

        $query = GhlToken::query();
        if ($locationId) {
            $query->where('location_id', $locationId);
        } elseif ($companyId) {
            $query->where('company_id', $companyId);
        }

        $ghlToken = $query->first();

        if ($ghlToken) {
            $ghlToken->update([
                'access_token'  => $data['access_token'],
                'refresh_token' => $data['refresh_token'],
                'expires_at'    => $expiresAt,
                'scope'         => $data['scope'] ?? $ghlToken->scope,
                'user_id'       => $data['userId'] ?? $ghlToken->user_id,
                'user_type'     => $userType,
                'company_id'    => $companyId ?? $ghlToken->company_id,
                'is_active'     => true,
            ]);
        } else {
            $ghlToken = GhlToken::create([
                'location_id'   => $locationId,
                'company_id'    => $companyId,
                'user_id'       => $data['userId'] ?? null,
                'user_type'     => $userType,
                'access_token'  => $data['access_token'],
                'refresh_token' => $data['refresh_token'],
                'expires_at'    => $expiresAt,
                'scope'         => $data['scope'] ?? null,
                'is_active'     => true,
            ]);
        }

        return $ghlToken;
    }

    /**
     * Update an existing token record with refreshed data
     */
    protected function updateTokenRecord(GhlToken $ghlToken, array $data): GhlToken
    {
        $expiresIn = $data['expires_in'] ?? 86400;
        $expiresAt = Carbon::now()->addSeconds($expiresIn);

        $ghlToken->update([
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'expires_at'    => $expiresAt,
            'scope'         => $data['scope'] ?? $ghlToken->scope,
            'is_active'     => true,
        ]);

        return $ghlToken;
    }
}
