<?php

namespace App\Services\Ghl;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class GhlCustomFieldService
{
    protected GhlAuthService $authService;
    protected string $baseUrl;

    public function __construct(GhlAuthService $authService)
    {
        $this->authService = $authService;
        $this->baseUrl     = rtrim(config('ghl.base_url', 'https://services.leadconnectorhq.com'), '/');
    }

    /**
     * Send an authorized HTTP request to the GoHighLevel API
     */
    protected function ghlRequest(string $method, string $endpoint, string $locationId, array $data = [])
    {
        $token = $this->authService->getValidAccessToken($locationId);

        $client = Http::withHeaders([
            'Authorization' => "Bearer {$token}",
            'Version'       => config('ghl.api_version', '2021-07-28'),
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ])->timeout(15);

        $url = "{$this->baseUrl}/" . ltrim($endpoint, '/');

        return match (strtoupper($method)) {
            'GET'    => $client->get($url, $data),
            'POST'   => $client->post($url, $data),
            'PUT'    => $client->put($url, $data),
            'PATCH'  => $client->patch($url, $data),
            'DELETE' => $client->delete($url, $data),
            default  => throw new Exception("Unsupported HTTP method: {$method}"),
        };
    }

    /**
     * Get or create the required custom fields ('WESS Code' and 'Last Visit') for a GHL location
     *
     * Flow:
     * 1. Check local cache to avoid duplicate API lookups.
     * 2. Query GET /locations/{locationId}/customFields to check if fields exist.
     * 3. If field exists, extract its UUID / ID.
     * 4. If field does not exist, create it via POST /locations/{locationId}/customFields.
     * 5. Cache and return the field IDs.
     *
     * @param string $locationId
     * @return array ['wess_code_id' => ?string, 'last_visit_id' => ?string]
     */
    public function getOrCreateContactCustomFields(string $locationId): array
    {
        $cacheKey = "ghl_custom_fields_map_{$locationId}";
        $cached = Cache::get($cacheKey);
        if ($cached && !empty($cached['wess_code_id']) && !empty($cached['last_visit_id'])) {
            return $cached;
        }

        $wessCodeId  = $cached['wess_code_id'] ?? null;
        $lastVisitId = $cached['last_visit_id'] ?? null;

        try {
            // 1. Fetch all existing custom fields for this location
            $res = $this->ghlRequest('GET', "locations/{$locationId}/customFields", $locationId);

            if ($res->successful()) {
                $customFields = $res->json('customFields') ?? $res->json() ?? [];
                if (!is_array($customFields) && isset($customFields['customFields'])) {
                    $customFields = $customFields['customFields'];
                }

                foreach ($customFields as $field) {
                    if (!is_array($field)) continue;
                    $name    = strtolower(trim($field['name'] ?? ''));
                    $fieldId = $field['id'] ?? null;

                    if (!$fieldId) continue;

                    // Match 'WESS Code'
                    if (in_array($name, ['wess code', 'wess customer code', 'wess customer id', 'wess id'])) {
                        $wessCodeId = $fieldId;
                    }

                    // Match 'Last Visit'
                    if (in_array($name, ['last visit', 'wess last visit', 'last visit date'])) {
                        $lastVisitId = $fieldId;
                    }
                }
            } else {
                Log::warning("Could not list GHL custom fields for location {$locationId}: HTTP {$res->status()} - {$res->body()}");
            }

            // 2. If 'WESS Code' does not exist in GHL, create it
            if (!$wessCodeId) {
                $createCodeRes = $this->ghlRequest('POST', "locations/{$locationId}/customFields", $locationId, [
                    'name'        => 'WESS Code',
                    'dataType'    => 'TEXT',
                    'model'       => 'contact',
                    'placeholder' => 'WESS Customer / Member ID',
                ]);

                if ($createCodeRes->successful()) {
                    $created = $createCodeRes->json('customField') ?? $createCodeRes->json();
                    $wessCodeId = $created['id'] ?? null;
                    Log::info("Successfully created GHL custom field 'WESS Code' (ID: {$wessCodeId}) for location {$locationId}");
                } else {
                    Log::warning("Failed to create GHL custom field 'WESS Code' for location {$locationId}: {$createCodeRes->body()}");
                }
            }

            // 3. If 'Last Visit' does not exist in GHL, create it
            if (!$lastVisitId) {
                $createVisitRes = $this->ghlRequest('POST', "locations/{$locationId}/customFields", $locationId, [
                    'name'        => 'Last Visit',
                    'dataType'    => 'TEXT',
                    'model'       => 'contact',
                    'placeholder' => 'Date of last salon / spa visit',
                ]);

                if ($createVisitRes->successful()) {
                    $created = $createVisitRes->json('customField') ?? $createVisitRes->json();
                    $lastVisitId = $created['id'] ?? null;
                    Log::info("Successfully created GHL custom field 'Last Visit' (ID: {$lastVisitId}) for location {$locationId}");
                } else {
                    Log::warning("Failed to create GHL custom field 'Last Visit' for location {$locationId}: {$createVisitRes->body()}");
                }
            }

            $resolved = [
                'wess_code_id'  => $wessCodeId,
                'last_visit_id' => $lastVisitId,
            ];

            // Cache mapping for 24 hours
            if ($wessCodeId || $lastVisitId) {
                Cache::put($cacheKey, $resolved, now()->addDay());
            }

            return $resolved;

        } catch (Exception $e) {
            Log::error("Exception resolving custom fields for location {$locationId}: " . $e->getMessage());
            return [
                'wess_code_id'  => $wessCodeId,
                'last_visit_id' => $lastVisitId,
            ];
        }
    }

    /**
     * Populate / Update custom field values on a GHL Contact record
     *
     * @param string $locationId
     * @param string $contactId
     * @param string|null $wessCode
     * @param string|null $lastVisit
     * @return bool
     */
    public function updateContactCustomFields(string $locationId, string $contactId, ?string $wessCode = null, ?string $lastVisit = null): bool
    {
        if (empty($locationId) || empty($contactId)) {
            return false;
        }

        $fields = $this->getOrCreateContactCustomFields($locationId);
        $customFieldsPayload = [];

        if (!empty($wessCode) && !empty($fields['wess_code_id'])) {
            $customFieldsPayload[] = [
                'id'          => $fields['wess_code_id'],
                'field_value' => (string)$wessCode,
                'value'       => (string)$wessCode,
            ];
        }

        if (!empty($lastVisit) && !empty($fields['last_visit_id'])) {
            $customFieldsPayload[] = [
                'id'          => $fields['last_visit_id'],
                'field_value' => (string)$lastVisit,
                'value'       => (string)$lastVisit,
            ];
        }

        if (empty($customFieldsPayload)) {
            return false;
        }

        try {
            $res = $this->ghlRequest('PUT', "contacts/{$contactId}", $locationId, [
                'customFields' => $customFieldsPayload,
            ]);

            $success = in_array($res->status(), [200, 201]);

            if (!$success) {
                Log::warning("Could not update custom fields for GHL contact {$contactId}: HTTP {$res->status()} - {$res->body()}");
            } else {
                Log::info("Successfully updated GHL contact {$contactId} with custom fields", [
                    'wess_code'  => $wessCode,
                    'last_visit' => $lastVisit,
                ]);
            }

            return $success;

        } catch (Exception $e) {
            Log::error("Exception updating GHL contact custom fields for {$contactId}: " . $e->getMessage());
            return false;
        }
    }
}
