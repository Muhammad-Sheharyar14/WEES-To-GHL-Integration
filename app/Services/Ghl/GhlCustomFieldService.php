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
     * Supported custom field definitions with aliases and metadata
     */
    protected array $fieldDefinitions = [
        'wess_code' => [
            'name'        => 'WESS Code',
            'dataType'    => 'TEXT',
            'placeholder' => 'WESS Customer / Member ID',
            'aliases'     => ['wess code', 'wess customer code', 'wess customer id', 'wess id', 'code'],
        ],
        'last_visit' => [
            'name'        => 'Last Visit',
            'dataType'    => 'TEXT',
            'placeholder' => 'Date of last salon / spa visit',
            'aliases'     => ['last visit', 'wess last visit', 'last visit date'],
        ],
        'salutation' => [
            'name'        => 'Salutation',
            'dataType'    => 'TEXT',
            'placeholder' => 'Mr, Mrs, Ms, Miss, Dr',
            'aliases'     => ['salutation', 'title', 'wess salutation'],
        ],
        'gender' => [
            'name'        => 'Gender',
            'dataType'    => 'TEXT',
            'placeholder' => 'M / F / Male / Female',
            'aliases'     => ['gender', 'sex', 'wess gender'],
        ],
        'nric' => [
            'name'        => 'NRIC',
            'dataType'    => 'TEXT',
            'placeholder' => 'NRIC / Passport / ID Number',
            'aliases'     => ['nric', 'ic no', 'ic_no', 'nric/fin', 'fin', 'id number'],
        ],
        'birthday_month' => [
            'name'        => 'Birthday Month',
            'dataType'    => 'TEXT',
            'placeholder' => 'e.g. January, February, etc.',
            'aliases'     => ['birthday month', 'birth month', 'bday month'],
        ],
        'preferred_name' => [
            'name'        => 'Preferred Name',
            'dataType'    => 'TEXT',
            'placeholder' => 'Preferred Name / Nickname',
            'aliases'     => ['preferred name', 'nickname', 'pref name'],
        ],
    ];

    /**
     * Get or create the required custom fields for a GHL location
     *
     * Flow:
     * 1. Check local cache to avoid duplicate API lookups.
     * 2. Query GET /locations/{locationId}/customFields to check which fields exist.
     * 3. For any missing fields, create them via POST /locations/{locationId}/customFields.
     * 4. Cache and return the field IDs.
     *
     * @param string $locationId
     * @return array Map of field keys to field IDs (e.g. 'wess_code_id' => string)
     */
    public function getOrCreateContactCustomFields(string $locationId): array
    {
        $cacheKey = "ghl_custom_fields_map_{$locationId}";
        $cached = Cache::get($cacheKey);
        if ($cached && is_array($cached) && !empty($cached['wess_code_id'])) {
            return $cached;
        }

        $resolved = $cached ?? [];

        try {
            // 1. Fetch all existing custom fields for this location
            $res = $this->ghlRequest('GET', "locations/{$locationId}/customFields", $locationId);

            $existingFields = [];
            if ($res->successful()) {
                $customFields = $res->json('customFields') ?? $res->json() ?? [];
                if (!is_array($customFields) && isset($customFields['customFields'])) {
                    $customFields = $customFields['customFields'];
                }

                foreach ($customFields as $field) {
                    if (!is_array($field)) continue;
                    $fieldId = $field['id'] ?? null;
                    if (!$fieldId) continue;

                    $name     = strtolower(trim($field['name'] ?? ($field['label'] ?? '')));
                    $fieldKey = strtolower(trim($field['fieldKey'] ?? ($field['key'] ?? '')));

                    if ($name) {
                        $existingFields[$name] = $fieldId;
                    }
                    if ($fieldKey) {
                        $existingFields[$fieldKey] = $fieldId;
                        if (str_starts_with($fieldKey, 'contact.')) {
                            $existingFields[substr($fieldKey, 8)] = $fieldId;
                        }
                    }
                }
            } else {
                Log::warning("Could not list GHL custom fields for location {$locationId}: HTTP {$res->status()} - {$res->body()}");
            }

            // 2. Resolve or create each supported field
            foreach ($this->fieldDefinitions as $key => $def) {
                $idKey = "{$key}_id";
                $fieldId = $resolved[$idKey] ?? null;

                if (!$fieldId) {
                    // Check if existing field matches any alias
                    foreach ($def['aliases'] as $alias) {
                        if (isset($existingFields[$alias])) {
                            $fieldId = $existingFields[$alias];
                            break;
                        }
                    }
                }

                // If not found in GHL, create it
                if (!$fieldId) {
                    $createRes = $this->ghlRequest('POST', "locations/{$locationId}/customFields", $locationId, [
                        'name'        => $def['name'],
                        'dataType'    => $def['dataType'],
                        'model'       => 'contact',
                        'placeholder' => $def['placeholder'],
                    ]);

                    if ($createRes->successful()) {
                        $created = $createRes->json('customField') ?? $createRes->json();
                        $fieldId = $created['id'] ?? null;
                        Log::info("Successfully created GHL custom field '{$def['name']}' (ID: {$fieldId}) for location {$locationId}");
                    } else {
                        Log::warning("Failed to create GHL custom field '{$def['name']}' for location {$locationId}: {$createRes->body()}");
                    }
                }

                $resolved[$idKey] = $fieldId;
            }

            // Cache mapping for 24 hours if at least one core field was resolved
            if (!empty($resolved['wess_code_id']) || !empty($resolved['last_visit_id'])) {
                Cache::put($cacheKey, $resolved, now()->addDay());
            }

            return $resolved;

        } catch (Exception $e) {
            Log::error("Exception resolving custom fields for location {$locationId}: " . $e->getMessage());
            return $resolved;
        }
    }

    /**
     * Populate / Update custom field values on a GHL Contact record
     *
     * @param string $locationId
     * @param string $contactId
     * @param string|null $wessCode
     * @param string|null $lastVisit
     * @param array $additionalFields Optional map of extra fields: ['salutation' => '...', 'gender' => '...', 'nric' => '...', 'contact_type' => '...', 'birthday_month' => '...', 'preferred_name' => '...']
     * @return bool
     */
    public function updateContactCustomFields(
        string $locationId, 
        string $contactId, 
        ?string $wessCode = null, 
        ?string $lastVisit = null,
        array $additionalFields = []
    ): bool
    {
        if (empty($locationId) || empty($contactId)) {
            return false;
        }

        $fields = $this->getOrCreateContactCustomFields($locationId);
        $customFieldsPayload = [];

        // 1. Core WESS Code
        if (!empty($wessCode) && !empty($fields['wess_code_id'])) {
            $customFieldsPayload[] = [
                'id'          => $fields['wess_code_id'],
                'field_value' => (string)$wessCode,
                'value'       => (string)$wessCode,
            ];
        }

        // 2. Core Last Visit
        if (!empty($lastVisit) && !empty($fields['last_visit_id'])) {
            $customFieldsPayload[] = [
                'id'          => $fields['last_visit_id'],
                'field_value' => (string)$lastVisit,
                'value'       => (string)$lastVisit,
            ];
        }

        // 3. Additional custom fields (Salutation, Gender, NRIC, Contact Type, Birthday Month, Preferred Name)
        foreach ($additionalFields as $key => $val) {
            if ($val === null || $val === '') {
                continue;
            }
            $cleanKey = strtolower(str_replace([' ', '-'], '_', $key));
            $idKey    = "{$cleanKey}_id";

            if (!empty($fields[$idKey])) {
                $customFieldsPayload[] = [
                    'id'          => $fields[$idKey],
                    'field_value' => (string)$val,
                    'value'       => (string)$val,
                ];
            }
        }

        $contactPayload = [];
        if (!empty($customFieldsPayload)) {
            $contactPayload['customFields'] = $customFieldsPayload;
        }

        // Native CONTACT SOURCE in MiniChat X (Martin requirement: use CONTACT SOURCE, do not touch Contact type)
        $sourceVal = $additionalFields['contact_source'] ?? $additionalFields['source'] ?? null;
        if (!empty($sourceVal)) {
            $contactPayload['source'] = (string) $sourceVal;
        }

        if (empty($contactPayload)) {
            return false;
        }

        try {
            $res = $this->ghlRequest('PUT', "contacts/{$contactId}", $locationId, $contactPayload);

            $success = in_array($res->status(), [200, 201]);

            if (!$success) {
                Log::warning("Could not update custom fields for GHL contact {$contactId}: HTTP {$res->status()} - {$res->body()}");
            } else {
                // Loop Prevention: Tag contact in cache for 45 seconds so immediate GHL ContactUpdate webhooks are ignored
                Cache::put("wess_origin_contact_{$contactId}", true, now()->addSeconds(45));

                Log::info("Successfully updated GHL contact {$contactId} with custom fields", [
                    'wess_code'         => $wessCode,
                    'last_visit'        => $lastVisit,
                    'additional_fields' => array_keys($additionalFields),
                    'source'            => $sourceVal,
                ]);
            }

            return $success;

        } catch (Exception $e) {
            Log::error("Exception updating GHL contact custom fields for {$contactId}: " . $e->getMessage());
            return false;
        }
    }
}
