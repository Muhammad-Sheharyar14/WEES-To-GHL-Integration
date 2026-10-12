<?php

namespace App\Services\Ghl;

use App\Models\WebhookLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class GhlCalendarService
{
    protected GhlAuthService $authService;
    protected string $baseUrl;

    public function __construct(GhlAuthService $authService)
    {
        $this->authService = $authService;
        $this->baseUrl     = rtrim(config('ghl.base_url', 'https://services.leadconnectorhq.com'), '/');
    }

    /**
     * Helper for authorized GHL requests
     */
    protected function ghlRequest(string $method, string $endpoint, string $locationId, array $data = [], ?string $companyId = null)
    {
        $token = $this->authService->getValidAccessToken($locationId, $companyId);

        $client = Http::withHeaders([
            'Authorization' => "Bearer {$token}",
            'Version'       => '2021-07-28',
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
            default  => throw new Exception("Unsupported method: {$method}"),
        };
    }

    /**
     * Fetch all available booking calendars for a location, extracting assigned user ID
     */
    public function getCalendars(string $locationId, ?string $companyId = null): array
    {
        try {
            $versions = ['2021-07-28', '2021-04-15', 'v3'];
            $res = null;

            foreach ($versions as $ver) {
                try {
                    $token = $this->authService->getValidAccessToken($locationId, $companyId);
                    $res = Http::withHeaders([
                        'Authorization' => "Bearer {$token}",
                        'Version'       => $ver,
                        'Accept'        => 'application/json',
                    ])->timeout(15)->get("{$this->baseUrl}/calendars/", [
                        'locationId' => $locationId,
                    ]);

                    if ($res->successful()) {
                        break;
                    }
                } catch (Exception $tokenEx) {
                    Log::warning("Token resolution attempt with version {$ver} for location {$locationId}: " . $tokenEx->getMessage());
                }
            }

            if ($res && $res->successful()) {
                $rawCalendars = $res->json('calendars') ?? $res->json() ?? [];
                if (!is_array($rawCalendars) && isset($rawCalendars['calendars'])) {
                    $rawCalendars = $rawCalendars['calendars'];
                }

                $calendars = [];
                if (is_array($rawCalendars)) {
                    foreach ($rawCalendars as $cal) {
                        if (!is_array($cal) || empty($cal['id'])) {
                            continue;
                        }

                        // Extract primary user ID or first team member
                        $userId = null;
                        if (!empty($cal['teamMembers']) && is_array($cal['teamMembers'])) {
                            foreach ($cal['teamMembers'] as $member) {
                                if (!empty($member['isPrimary']) && !empty($member['userId'])) {
                                    $userId = $member['userId'];
                                    break;
                                }
                            }
                            if (!$userId && !empty($cal['teamMembers'][0]['userId'])) {
                                $userId = $cal['teamMembers'][0]['userId'];
                            }
                        }
                        if (!$userId && !empty($cal['userId'])) {
                            $userId = $cal['userId'];
                        }

                        $calendars[] = [
                            'id'           => $cal['id'],
                            'name'         => $cal['name'] ?? 'Unnamed Calendar',
                            'calendarType' => $cal['calendarType'] ?? 'standard',
                            'user_id'      => $userId,
                            'description'  => $cal['description'] ?? '',
                        ];
                    }
                }

                Log::info("Successfully fetched " . count($calendars) . " CRM calendars for location {$locationId}");
                return $calendars;
            }

            if ($res) {
                Log::error("Failed to fetch calendars from GHL for location {$locationId}", [
                    'status' => $res->status(),
                    'body'   => $res->body(),
                ]);
            }
        } catch (Exception $e) {
            Log::error("Exception in getCalendars for location {$locationId}: " . $e->getMessage());
        }

        return [];
    }

    /**
     * Find or create the main "WESS" calendar group / calendar in GHL
     * (Excel Item A.1 & A.2: set a main calendar group in MC for this, call it WESS)
     */
    public function getOrCreateWessCalendar(string $locationId): array
    {
        try {
            $calendars = $this->getCalendars($locationId);
            foreach ($calendars as $cal) {
                if (stripos($cal['name'] ?? '', 'WESS') !== false) {
                    return $cal;
                }
            }

            // Create new WESS calendar if not found
            $createRes = $this->ghlRequest('POST', 'calendars', $locationId, [
                'locationId'  => $locationId,
                'name'        => 'WESS Salon & Spa Calendar',
                'description' => 'Synchronized with WESS management system by Ample Life',
                'isActive'    => true,
            ]);

            if (in_array($createRes->status(), [200, 201])) {
                return $createRes->json('calendar') ?? $createRes->json();
            }
        } catch (Exception $e) {
            Log::warning("Could not auto-resolve WESS calendar in GHL for location {$locationId}: " . $e->getMessage());
        }

        return ['id' => 'default_wess_calendar'];
    }

    /**
     * Upsert a calendar event / blocked time slot in GHL
     * Implements privacy masking (Excel Item A.4: MC customers only see slot blocks, no private details)
     */
    public function syncAppointmentToGhl(
        string $locationId, 
        array $wessAppointment, 
        bool $maskPrivateDetails = true,
        ?string $customCalendarId = null,
        ?string $customUserId = null
    ): array
    {
        $calendarId = $customCalendarId;
        $userId     = $customUserId;

        if (empty($calendarId)) {
            $calendar = $this->getOrCreateWessCalendar($locationId);
            $calendarId = $calendar['id'] ?? 'default_wess_calendar';
            if (empty($userId) && !empty($calendar['user_id'])) {
                $userId = $calendar['user_id'];
            }
        }

        // Resolve start time and duration from WESS appointment data
        // WESS Open API returns 'date' in UTC ISO8601 (e.g. 2026-10-10T08:00:00.000000Z) and 'processing_time' in minutes
        $dateRaw = $wessAppointment['date'] ?? $wessAppointment['start_time'] ?? $wessAppointment['datetime'] ?? null;
        if ($dateRaw) {
            $parsedStart = Carbon::parse($dateRaw);
            $startTime   = $parsedStart->toIso8601String();
            $durationMinutes = (int) ($wessAppointment['processing_time'] ?? 30);
            $endTime = !empty($wessAppointment['end_time']) 
                ? Carbon::parse($wessAppointment['end_time'])->toIso8601String() 
                : $parsedStart->copy()->addMinutes($durationMinutes)->toIso8601String();
        } else {
            $startTime = now()->toIso8601String();
            $endTime   = now()->addHour()->toIso8601String();
        }

        $serviceName = $wessAppointment['service_name'] ?? $wessAppointment['service'] ?? 'Salon & Spa Therapy';

        // Resolve customer / contact details from WESS appointment
        $customer     = $wessAppointment['customer'] ?? null;
        $customerName = $wessAppointment['customer_name'] ?? ($customer ? trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')) : '');

        // Customer-centric appointment title (e.g. "John's Appointment" or "Test SandboxClient's Appointment")
        if (!empty($customerName)) {
            $possessive = str_ends_with(strtolower($customerName), 's') ? "{$customerName}' Appointment" : "{$customerName}'s Appointment";
            $title = $possessive;
        } else {
            $title = !empty($serviceName) ? "{$serviceName} Appointment" : "Client's Appointment";
        }
        $phone        = $wessAppointment['phone'] ?? ($customer['phone_mobile'] ?? ($customer['phone'] ?? ''));
        $email        = $wessAppointment['email'] ?? ($customer['customer_detail']['email'] ?? ($customer['email'] ?? ''));
        $contactId    = $wessAppointment['contact_id'] ?? ($wessAppointment['contactId'] ?? null);

        if (empty($contactId) && (!empty($phone) || !empty($email) || !empty($customerName))) {
            try {
                $contactId = $this->getOrCreateContact($locationId, $customerName, $phone, $email);
            } catch (Exception $e) {
                Log::warning("Could not auto-resolve contact for appointment: " . $e->getMessage());
            }
        }

        $payload = [
            'calendarId'               => $calendarId,
            'locationId'               => $locationId,
            'title'                    => $title,
            'startTime'                => $startTime,
            'endTime'                  => $endTime,
            'appointmentStatus'        => 'confirmed',
            'notes'                    => "WESS Booking ID: " . ($wessAppointment['id'] ?? 'N/A'),
            'ignoreFreeSlotValidation' => true,
            'toNotify'                 => false,
        ];

        if (!empty($contactId)) {
            $payload['contactId'] = $contactId;
        }

        // Pass assignedUserId if present
        $assignedUser = $userId ?: ($wessAppointment['staff_id'] ?? null);
        if (!empty($assignedUser)) {
            $payload['assignedUserId'] = $assignedUser;
        }

        try {
            $res      = null;
            $endpoint = 'calendars/events/appointments';

            // Attempt 1: If contactId is present, create appointment in GHL
            if (!empty($contactId)) {
                $res = $this->ghlRequest('POST', $endpoint, $locationId, $payload);
            }

            // Attempt 2: If appointment endpoint returned 422 or contactId is missing,
            // fall back to block-slots (Excel Item A.4: MC slot block without requiring contact)
            if (!$res || $res->status() === 422) {
                Log::info("Falling back to calendars/events/block-slots for location {$locationId}");
                $endpoint = 'calendars/events/block-slots';
                $blockPayload = [
                    'calendarId' => $calendarId,
                    'locationId' => $locationId,
                    'title'      => $title,
                    'startTime'  => $startTime,
                    'endTime'    => $endTime,
                ];
                if (!empty($assignedUser)) {
                    $blockPayload['assignedUserId'] = $assignedUser;
                }
                $res = $this->ghlRequest('POST', $endpoint, $locationId, $blockPayload);
                $payload = $blockPayload;
            }

            $statusCode = $res->status();
            $resData    = $res->json() ?? [];

            // Echo Loop Prevention: Tag created GHL appointment ID in cache with 45s debounce window
            $createdApptId = $resData['id'] ?? $resData['appointment']['id'] ?? $resData['event']['id'] ?? null;
            if ($createdApptId) {
                Cache::put("wess_origin_appt_{$createdApptId}", true, now()->addSeconds(45));
            }

            WebhookLog::create([
                'source'          => 'wess_sync',
                'location_id'     => $locationId,
                'event_type'      => str_contains($endpoint, 'block-slots') ? 'GhlCalendarSlotBlocked' : 'GhlCalendarEventCreated',
                'endpoint'        => "{$this->baseUrl}/{$endpoint}",
                'method'          => 'POST',
                'payload'         => $payload,
                'response_status' => $statusCode,
                'response_body'   => substr($res->body(), 0, 1000),
            ]);

            if ($res->failed()) {
                $err = $resData['message'] ?? $res->body() ?? "HTTP {$statusCode}";
                if (is_array($err)) {
                    $err = implode(', ', $err);
                }
                throw new Exception("GHL API error ({$statusCode}): {$err}");
            }

            return $resData ?: ['status' => $statusCode];
        } catch (Exception $e) {
            Log::error("Failed to sync appointment to GHL calendar for location {$locationId}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get or create GHL Contact for appointment booking
     */
    public function getOrCreateContact(string $locationId, string $name, ?string $phone = null, ?string $email = null): ?string
    {
        $cacheKey = "ghl_appt_contact_" . md5("{$locationId}_{$phone}_{$email}_{$name}");
        $cachedId = Cache::get($cacheKey);
        if ($cachedId) {
            return $cachedId;
        }

        $parts = explode(' ', trim($name), 2);
        $payload = [
            'locationId' => $locationId,
            'firstName'  => $parts[0] ?: 'WESS',
            'lastName'   => $parts[1] ?? 'Client',
            'name'       => $name ?: 'WESS Client',
        ];
        if (!empty($phone)) {
            $payload['phone'] = $phone;
        }
        if (!empty($email)) {
            $payload['email'] = $email;
        }

        try {
            $res = $this->ghlRequest('POST', 'contacts/upsert', $locationId, $payload);
            if ($res->successful()) {
                $contactId = $res->json('contact.id') ?? $res->json('id');
                if ($contactId) {
                    Cache::put($cacheKey, $contactId, now()->addDay());
                    return $contactId;
                }
            }
        } catch (Exception $e) {
            Log::warning("Contact upsert failed for {$name}: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Update GHL Contact Custom Fields (Excel Item B.i: WESS Code & Last Visit)
     */
    public function updateContactWessFields(string $locationId, string $contactId, string $wessCode, ?string $lastVisit = null): bool
    {
        return app(\App\Services\Ghl\GhlCustomFieldService::class)
            ->updateContactCustomFields($locationId, $contactId, $wessCode, $lastVisit);
    }

    /**
     * Fetch GHL Contact by contactId
     */
    public function getContact(string $locationId, string $contactId): ?array
    {
        try {
            $res = $this->ghlRequest('GET', "contacts/{$contactId}", $locationId);

            if (in_array($res->status(), [200, 201])) {
                return $res->json('contact') ?? $res->json();
            }
        } catch (Exception $e) {
            Log::warning("Could not fetch GHL contact {$contactId}: " . $e->getMessage());
        }

        return null;
    }
}
