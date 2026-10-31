<?php

namespace App\Services\Ghl;

use App\Models\SyncedAppointment;
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

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
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
     * Fetch CRM Users for a location
     */
    public function getUsers(string $locationId, ?string $companyId = null): array
    {
        try {
            $token = $this->authService->getValidAccessToken($locationId, $companyId);
            $res = Http::withHeaders([
                'Authorization' => "Bearer {$token}",
                'Version'       => '2021-07-28',
                'Accept'        => 'application/json',
            ])->timeout(15)->get("{$this->baseUrl}/users/", [
                'locationId' => $locationId,
            ]);

            if ($res->successful()) {
                $rawUsers = $res->json('users') ?? $res->json() ?? [];
                if (!is_array($rawUsers) && isset($rawUsers['users'])) {
                    $rawUsers = $rawUsers['users'];
                }

                $users = [];
                if (is_array($rawUsers)) {
                    foreach ($rawUsers as $u) {
                        if (!is_array($u) || empty($u['id'])) {
                            continue;
                        }
                        $users[] = [
                            'id'        => $u['id'],
                            'name'      => $u['name'] ?? trim(($u['firstName'] ?? '') . ' ' . ($u['lastName'] ?? '')),
                            'firstName' => $u['firstName'] ?? '',
                            'lastName'  => $u['lastName'] ?? '',
                            'email'     => $u['email'] ?? '',
                            'phone'     => $u['phone'] ?? '',
                        ];
                    }
                }

                Log::info("Successfully fetched " . count($users) . " CRM users for location {$locationId}");
                return $users;
            }

            if ($res) {
                Log::warning("Failed to fetch users from CRM for location {$locationId}", [
                    'status' => $res->status(),
                    'body'   => $res->body(),
                ]);
            }
        } catch (Exception $e) {
            Log::error("Exception in getUsers for location {$locationId}: " . $e->getMessage());
        }

        return [];
    }

    /**
     * Build an in-memory / cached map of Therapists to CRM Personal Calendars
     * Cached with a 15-minute TTL to protect CRM API rate limits during bulk sync loops.
     */
    public function getTherapistCalendarMap(string $locationId, ?string $companyId = null, bool $forceRefresh = false): array
    {
        $cacheKey = "ghl_therapist_map_{$locationId}";
        if (!$forceRefresh) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && !empty($cached)) {
                return $cached;
            }
        }

        $users     = $this->getUsers($locationId, $companyId);
        $calendars = $this->getCalendars($locationId, $companyId);

        $defaultCalId  = null;
        $defaultUserId = null;

        // Determine default fallback calendar (first calendar or WESS calendar)
        foreach ($calendars as $cal) {
            if (empty($defaultCalId)) {
                $defaultCalId  = $cal['id'];
                $defaultUserId = $cal['user_id'] ?? null;
            }
            if (stripos($cal['name'] ?? '', 'WESS') !== false) {
                $defaultCalId  = $cal['id'];
                $defaultUserId = $cal['user_id'] ?? $defaultUserId;
                break;
            }
        }

        $map = [
            'by_code'    => [], // e.g. 'EP01009' => ['calendar_id' => ..., 'user_id' => ...]
            'by_name'    => [], // e.g. 'aarin' => ['calendar_id' => ..., 'user_id' => ...]
            'by_user_id' => [], // e.g. 'usr_123' => ['calendar_id' => ..., 'user_id' => ...]
            'by_cal_id'  => [], // e.g. 'cal_123' => ['calendar_id' => ..., 'user_id' => ...]
            'users'      => $users,
            'calendars'  => $calendars,
            '_default'   => [
                'calendar_id' => $defaultCalId,
                'user_id'     => $defaultUserId,
            ],
        ];

        // 1. Link each user to their calendar
        foreach ($users as $user) {
            $uId    = $user['id'];
            $uName  = $user['name'] ?? '';
            $uFirst = trim($user['firstName'] ?? '');
            $uLast  = trim($user['lastName'] ?? '');

            // Find matching calendar for this user:
            // Check A: calendar.user_id === user.id
            // Check B: calendar name contains user's first name, full name, or last name (code)
            $matchedCal = null;
            foreach ($calendars as $cal) {
                if (!empty($cal['user_id']) && $cal['user_id'] === $uId) {
                    $matchedCal = $cal;
                    break;
                }
            }

            if (!$matchedCal) {
                foreach ($calendars as $cal) {
                    $cName = $cal['name'] ?? '';
                    if (!empty($uLast) && stripos($cName, $uLast) !== false) {
                        $matchedCal = $cal;
                        break;
                    }
                    if (!empty($uFirst) && stripos($cName, $uFirst) !== false) {
                        $matchedCal = $cal;
                        break;
                    }
                }
            }

            $calId = $matchedCal['id'] ?? $defaultCalId;
            $entry = [
                'calendar_id'   => $calId,
                'calendar_name' => $matchedCal['name'] ?? '',
                'user_id'       => $uId,
                'user_name'     => $uName,
                'first_name'    => $uFirst,
                'last_name'     => $uLast,
            ];

            // Index by User ID
            $map['by_user_id'][$uId] = $entry;

            // Index by Calendar ID
            if (!empty($matchedCal['id'])) {
                $map['by_cal_id'][$matchedCal['id']] = $entry;
            }

            // Index by employee code if present in lastName or name (e.g. EP01009)
            if (!empty($uLast)) {
                $map['by_code'][strtoupper(trim($uLast))] = $entry;
                if (preg_match('/(EP\d+)/i', $uLast, $m)) {
                    $map['by_code'][strtoupper($m[1])] = $entry;
                }
            }
            if (preg_match('/(EP\d+)/i', $uName, $m)) {
                $map['by_code'][strtoupper($m[1])] = $entry;
            }

            // Index by normalized names
            if (!empty($uFirst)) {
                $normFirst = strtolower(preg_replace('/[^a-z0-9]/', '', $uFirst));
                $map['by_name'][$normFirst] = $entry;
            }
            if (!empty($uName)) {
                $normFull = strtolower(preg_replace('/[^a-z0-9]/', '', $uName));
                $map['by_name'][$normFull] = $entry;
            }
        }

        // Cache for 15 minutes
        Cache::put($cacheKey, $map, now()->addMinutes(15));
        return $map;
    }

    /**
     * Resolve therapist personal calendar and user ID for a WESS appointment
     */
    public function resolveTherapistCalendar(
        array $therapistMap, 
        array $wessAppointment, 
        ?string $fallbackCalendarId = null, 
        ?string $fallbackUserId = null
    ): array
    {
        $defaultCalId  = $fallbackCalendarId ?: ($therapistMap['_default']['calendar_id'] ?? null);
        $defaultUserId = $fallbackUserId ?: ($therapistMap['_default']['user_id'] ?? null);

        $employees = $wessAppointment['employees'] ?? [];
        if (empty($employees) || !is_array($employees)) {
            return [
                'calendar_id' => $defaultCalId,
                'user_id'     => $defaultUserId,
                'matched_by'  => 'default_no_employee',
            ];
        }

        $emp = $employees[0] ?? [];
        $empCode  = strtoupper(trim($emp['code'] ?? ($emp['employee_code'] ?? '')));
        $empFirst = trim($emp['first_name'] ?? '');
        $empLast  = trim($emp['last_name'] ?? '');

        // 1. Try matching by employee code (e.g. EP01009)
        if (!empty($empCode) && isset($therapistMap['by_code'][$empCode])) {
            $m = $therapistMap['by_code'][$empCode];
            return [
                'calendar_id' => $m['calendar_id'] ?: $defaultCalId,
                'user_id'     => $m['user_id'] ?: $defaultUserId,
                'matched_by'  => "code:{$empCode}",
            ];
        }

        // Check if last_name has code (e.g. EP01009)
        if (!empty($empLast) && preg_match('/(EP\d+)/i', $empLast, $codeMatch)) {
            $code = strtoupper($codeMatch[1]);
            if (isset($therapistMap['by_code'][$code])) {
                $m = $therapistMap['by_code'][$code];
                return [
                    'calendar_id' => $m['calendar_id'] ?: $defaultCalId,
                    'user_id'     => $m['user_id'] ?: $defaultUserId,
                    'matched_by'  => "last_name_code:{$code}",
                ];
            }
        }

        // 2. Try matching by normalized full name (e.g. "Xiao Yu" => "xiaoyu")
        $fullNameNorm = strtolower(preg_replace('/[^a-z0-9]/', '', "{$empFirst}{$empLast}"));
        if (!empty($fullNameNorm) && isset($therapistMap['by_name'][$fullNameNorm])) {
            $m = $therapistMap['by_name'][$fullNameNorm];
            return [
                'calendar_id' => $m['calendar_id'] ?: $defaultCalId,
                'user_id'     => $m['user_id'] ?: $defaultUserId,
                'matched_by'  => "full_name:{$fullNameNorm}",
            ];
        }

        // 3. Try matching by normalized first name (e.g. "Aarin" => "aarin", "Cherry" => "cherry")
        $firstNameNorm = strtolower(preg_replace('/[^a-z0-9]/', '', $empFirst));
        if (!empty($firstNameNorm) && isset($therapistMap['by_name'][$firstNameNorm])) {
            $m = $therapistMap['by_name'][$firstNameNorm];
            return [
                'calendar_id' => $m['calendar_id'] ?: $defaultCalId,
                'user_id'     => $m['user_id'] ?: $defaultUserId,
                'matched_by'  => "first_name:{$firstNameNorm}",
            ];
        }

        // 4. Fallback to default calendar
        return [
            'calendar_id' => $defaultCalId,
            'user_id'     => $defaultUserId,
            'matched_by'  => 'fallback_default',
        ];
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

        if (empty($calendarId) || empty($userId)) {
            $config = \App\Models\WessConfig::where('location_id', $locationId)->first();
            if (empty($calendarId)) {
                $calendarId = $config?->calendar_id;
            }
            if (empty($userId)) {
                $userId = $config?->calendar_user_id;
            }
        }

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

        // Dynamic appointment status mapping (WESS -> CRM)
        // GHL calendar grid filters by 'confirmed' by default; mapping active WESS requests to 'confirmed' prevents them from being hidden
        $rawStatus = strtolower(trim($wessAppointment['status'] ?? 'open'));
        $appointmentStatus = match ($rawStatus) {
            'request', 'open', 'confirmed' => 'confirmed',
            'cancelled', 'deleted'         => 'cancelled',
            'completed', 'done'            => 'completed',
            'arrived', 'check-in'          => 'showed',
            'no show', 'no-show'           => 'noshow',
            default                        => 'confirmed',
        };

        $payload = [
            'calendarId'               => $calendarId,
            'locationId'               => $locationId,
            'title'                    => $title,
            'startTime'                => $startTime,
            'endTime'                  => $endTime,
            'appointmentStatus'        => $appointmentStatus,
            'notes'                    => "WESS Booking ID: " . ($wessAppointment['id'] ?? 'N/A'),
            'ignoreFreeSlotValidation' => true,
            'toNotify'                 => false,
        ];

        if (!empty($contactId)) {
            $payload['contactId'] = $contactId;
        }

        // Pass assignedUserId if present
        $assignedUser = $userId ?: ($wessAppointment['staff_id'] ?? null);

        // If assignedUser is not set, auto-resolve staff directly from the calendar's definition
        if (empty($assignedUser) && !empty($calendarId)) {
            try {
                $allCals = $this->getCalendars($locationId);
                foreach ($allCals as $c) {
                    if (($c['id'] ?? '') === $calendarId && !empty($c['user_id'])) {
                        $assignedUser = $c['user_id'];
                        break;
                    }
                }
            } catch (\Throwable $e) {
                Log::debug("Could not resolve calendar staff user_id: " . $e->getMessage());
            }
        }

        if (!empty($assignedUser)) {
            $payload['assignedUserId'] = $assignedUser;
        }

        // Check if this appointment was previously synced to GHL via synced_appointments DB table
        $wessApptId     = !empty($wessAppointment['id']) ? (string) $wessAppointment['id'] : null;
        $existingRecord = null;
        if ($wessApptId) {
            $existingRecord = SyncedAppointment::where('location_id', $locationId)
                ->where('wess_appointment_id', $wessApptId)
                ->first();
        }

        $existingGhlId = $existingRecord?->ghl_appointment_id;
        $isUpdate      = !empty($existingGhlId);

        // Layer 2 Real-Time Duplicate Check: If not in synced_appointments, check if CRM already has this event
        if (!$isUpdate && $wessApptId) {
            $existingCrmId = $this->findExistingCrmEventByWessId($locationId, $wessApptId, $startTime, $calendarId);
            if ($existingCrmId) {
                $existingGhlId = $existingCrmId;
                $isUpdate      = true;
                Log::info("Layer 2: Detected existing CRM appointment {$existingGhlId} for WESS #{$wessApptId}. Updating via PUT to prevent duplicate.");
            }
        }

        try {
            $res      = null;
            $endpoint = 'calendars/events/appointments';
            $method   = 'POST';

            // Attempt 1: If existing in GHL, update via PUT to prevent duplication; otherwise create via POST
            if ($isUpdate) {
                $endpoint = "calendars/events/appointments/{$existingGhlId}";
                $method   = 'PUT';
                $res      = $this->ghlRequest('PUT', $endpoint, $locationId, $payload);

                // If GHL returned 404 (e.g. appointment was deleted in GHL UI), fall back to re-creating via POST
                if ($res->status() === 404) {
                    Log::info("Existing GHL appointment {$existingGhlId} not found in GHL (404). Re-creating via POST.");
                    $endpoint = 'calendars/events/appointments';
                    $method   = 'POST';
                    $res      = !empty($contactId) ? $this->ghlRequest('POST', $endpoint, $locationId, $payload) : null;
                }
            } else {
                if (!empty($contactId)) {
                    $res = $this->ghlRequest('POST', $endpoint, $locationId, $payload);
                }
            }

            // Attempt 2: If appointment endpoint returned 422 or contactId is missing,
            // fall back to block-slots (Excel Item A.4: MC slot block without requiring contact)
            if (!$res || $res->status() === 422) {
                Log::info("Falling back to calendars/events/block-slots for location {$locationId}");

                // GHL Official Documentation Rule (https://marketplace.gohighlevel.com/docs/ghl/calendars/create-block-slot):
                // "Either calendarId or assignedUserId can be set, not both."
                // For staff/personal calendars, assignedUserId is used to block the user.
                // For general/unassigned event calendars, calendarId is used.
                $baseBlockPayload = [
                    'locationId' => $locationId,
                    'title'      => $title,
                    'startTime'  => $startTime,
                    'endTime'    => $endTime,
                ];

                $blockPayload = $baseBlockPayload;
                if (!empty($assignedUser)) {
                    $blockPayload['assignedUserId'] = $assignedUser;
                } elseif (!empty($calendarId)) {
                    $blockPayload['calendarId'] = $calendarId;
                }

                if ($isUpdate && $method === 'PUT') {
                    $endpoint = "calendars/events/block-slots/{$existingGhlId}";
                    $res      = $this->ghlRequest('PUT', $endpoint, $locationId, $blockPayload);
                    if ($res->status() === 404) {
                        $endpoint = 'calendars/events/block-slots';
                        $method   = 'POST';
                        $res      = $this->ghlRequest('POST', $endpoint, $locationId, $blockPayload);
                    }
                } else {
                    $endpoint = 'calendars/events/block-slots';
                    $method   = 'POST';
                    $res      = $this->ghlRequest('POST', $endpoint, $locationId, $blockPayload);
                }

                // Resilient Fallback: If CRM returned 400 Bad Request, try alternating between assignedUserId and calendarId
                if ($res && $res->status() === 400) {
                    $alternatePayload = $baseBlockPayload;
                    if (isset($blockPayload['assignedUserId']) && !empty($calendarId)) {
                        $alternatePayload['calendarId'] = $calendarId;
                        Log::info("Retrying block-slots with alternate parameter 'calendarId': {$calendarId}");
                        $altRes = $this->ghlRequest($method, $endpoint, $locationId, $alternatePayload);
                        if ($altRes->successful()) {
                            $res          = $altRes;
                            $blockPayload = $alternatePayload;
                        }
                    } elseif (isset($blockPayload['calendarId']) && !empty($assignedUser)) {
                        $alternatePayload['assignedUserId'] = $assignedUser;
                        Log::info("Retrying block-slots with alternate parameter 'assignedUserId': {$assignedUser}");
                        $altRes = $this->ghlRequest($method, $endpoint, $locationId, $alternatePayload);
                        if ($altRes->successful()) {
                            $res          = $altRes;
                            $blockPayload = $alternatePayload;
                        }
                    }
                }

                $payload = $blockPayload;
            }

            $statusCode = $res->status();
            $resData    = $res->json() ?? [];

            // Extract resolved GHL appointment ID
            $savedGhlId = $resData['id'] ?? $resData['appointment']['id'] ?? $resData['event']['id'] ?? $existingGhlId ?? null;

            // Echo Loop Prevention: Tag created/updated GHL appointment ID in cache with 45s debounce window
            if ($savedGhlId) {
                Cache::put("wess_origin_appt_{$savedGhlId}", true, now()->addSeconds(45));
            }

            $eventType = str_contains($endpoint, 'block-slots')
                ? ($method === 'PUT' ? 'GhlCalendarSlotUpdated' : 'GhlCalendarSlotBlocked')
                : ($method === 'PUT' ? 'GhlCalendarEventUpdated' : 'GhlCalendarEventCreated');

            $logResponseBody = [
                'ghl_response'         => $resData,
                'wess_raw_appointment' => $wessAppointment,
            ];

            WebhookLog::create([
                'source'          => 'wess_sync',
                'location_id'     => $locationId,
                'event_type'      => $eventType,
                'endpoint'        => "{$this->baseUrl}/{$endpoint}",
                'method'          => $method,
                'payload'         => [
                    'crm_payload'          => $payload,
                    'wess_raw_appointment' => $wessAppointment,
                ],
                'response_status' => $statusCode,
                'response_body'   => json_encode($logResponseBody),
            ]);

            if ($res->failed()) {
                $err = $resData['message'] ?? $res->body() ?? "HTTP {$statusCode}";
                if (is_array($err)) {
                    $err = implode(', ', $err);
                }
                throw new Exception("GHL API error ({$statusCode}): {$err}");
            }

            // Persist to synced_appointments DB table to permanently track and prevent duplicate bookings
            if ($wessApptId && $savedGhlId) {
                $syncHash = md5(($wessAppointment['date'] ?? '') . ($wessAppointment['status'] ?? '') . ($wessAppointment['processing_time'] ?? '') . ($wessAppointment['service_name'] ?? $wessAppointment['service'] ?? '') . ($wessAppointment['customer_name'] ?? ''));

                SyncedAppointment::updateOrCreate(
                    [
                        'location_id'         => $locationId,
                        'wess_appointment_id' => $wessApptId,
                    ],
                    [
                        'ghl_appointment_id' => (string) $savedGhlId,
                        'ghl_contact_id'     => $contactId ? (string) $contactId : null,
                        'calendar_id'        => (string) $calendarId,
                        'assigned_user_id'   => $assignedUser ? (string) $assignedUser : null,
                        'title'              => $title,
                        'status'             => (string) ($wessAppointment['status'] ?? 'confirmed'),
                        'start_time'         => $startTime,
                        'end_time'           => $endTime,
                        'sync_hash'          => $syncHash,
                        'last_synced_at'     => now(),
                    ]
                );
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
            'tags'       => ['WESS'],
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
     * Cancel or delete an appointment on the CRM calendar
     * Updates appointment status to 'cancelled' (preserving calendar history & freeing the slot)
     */
    public function cancelAppointmentInGhl(string $locationId, string $ghlAppointmentId): bool
    {
        try {
            // Echo Loop Prevention: Tag cancelled CRM appointment in cache for 45s
            Cache::put("wess_origin_appt_{$ghlAppointmentId}", true, now()->addSeconds(45));

            // 1. Update appointment status to 'cancelled' (preserves history in CRM calendar)
            $putRes = $this->ghlRequest('PUT', "calendars/events/appointments/{$ghlAppointmentId}", $locationId, [
                'appointmentStatus' => 'cancelled',
            ]);
            if ($putRes->successful() || $putRes->status() === 404) {
                Log::info("Successfully updated CRM appointment {$ghlAppointmentId} status to 'cancelled'.");
                return true;
            }

            // 2. Fallback: If it was a block-slot, delete via block-slots endpoint
            $blockDel = $this->ghlRequest('DELETE', "calendars/events/block-slots/{$ghlAppointmentId}", $locationId);
            if ($blockDel->successful() || $blockDel->status() === 404) {
                Log::info("Successfully deleted CRM block-slot {$ghlAppointmentId} after WESS cancellation.");
                return true;
            }

            // 3. Fallback: If PUT failed, try deleting via appointment endpoint
            $delRes = $this->ghlRequest('DELETE', "calendars/events/appointments/{$ghlAppointmentId}", $locationId);
            if ($delRes->successful() || $delRes->status() === 404) {
                Log::info("Successfully deleted CRM appointment {$ghlAppointmentId} after WESS cancellation fallback.");
                return true;
            }

            return false;
        } catch (\Throwable $e) {
            Log::warning("Could not cancel CRM appointment {$ghlAppointmentId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Add tags to a GHL Contact (Excel / CRM organization)
     */
    public function addContactTags(string $locationId, string $contactId, array $tags = ['WESS']): bool
    {
        try {
            $res = $this->ghlRequest('POST', "contacts/{$contactId}/tags", $locationId, [
                'tags' => $tags,
            ]);
            return in_array($res->status(), [200, 201]);
        } catch (Exception $e) {
            Log::warning("Failed to add tags to GHL contact {$contactId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update GHL Contact Custom Fields (WESS Code, Last Visit, and additional customer fields)
     */
    public function updateContactWessFields(string $locationId, string $contactId, string $wessCode, ?string $lastVisit = null, array $additionalFields = []): bool
    {
        return app(\App\Services\Ghl\GhlCustomFieldService::class)
            ->updateContactCustomFields($locationId, $contactId, $wessCode, $lastVisit, $additionalFields);
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

    /**
     * Fetch existing CRM calendar events for a location in a time range (Self-Healing Layer 2)
     */
    public function getCalendarEvents(string $locationId, $dateFrom, $dateTo, ?string $calendarId = null, ?string $companyId = null): array
    {
        try {
            $startMs = is_numeric($dateFrom) ? (int)$dateFrom : (int)(Carbon::parse($dateFrom)->startOfDay()->getTimestamp() * 1000);
            $endMs   = is_numeric($dateTo) ? (int)$dateTo : (int)(Carbon::parse($dateTo)->endOfDay()->getTimestamp() * 1000);

            $params = [
                'locationId' => $locationId,
                'startTime'  => (string) $startMs,
                'endTime'    => (string) $endMs,
            ];
            if (!empty($calendarId)) {
                $params['calendarId'] = $calendarId;
            }

            $res = $this->ghlRequest('GET', 'calendars/events', $locationId, $params, $companyId);

            if ($res && $res->successful()) {
                $json = $res->json();
                return $json['events'] ?? (is_array($json) ? $json : []);
            }

            Log::warning("Failed to fetch CRM calendar events for location {$locationId}: " . ($res ? $res->status() : 'null'));
        } catch (Exception $e) {
            Log::warning("Exception fetching CRM calendar events for location {$locationId}: " . $e->getMessage());
        }

        return [];
    }

    /**
     * Self-Healing Reconciliation: Scan existing CRM calendar events and auto-rebuild
     * synced_appointments table mappings to prevent any duplicates upon app reinstall/reconnect.
     */
    public function reconcileExistingCrmEvents(string $locationId, $dateFrom, $dateTo, ?string $companyId = null): int
    {
        $events = $this->getCalendarEvents($locationId, $dateFrom, $dateTo, null, $companyId);
        if (empty($events) || !is_array($events)) {
            return 0;
        }

        $reconciled = 0;

        foreach ($events as $event) {
            if (!is_array($event) || empty($event['id'])) {
                continue;
            }

            $wessApptId = null;

            // 1. Check notes for WESS Booking ID signature: "WESS Booking ID: 12345"
            $notes = $event['notes'] ?? ($event['description'] ?? '');
            if (preg_match('/WESS\s+Booking\s+ID:\s*([0-9a-zA-Z_-]+)/i', $notes, $matches)) {
                $wessApptId = $matches[1];
            }

            // 2. Fallback: Check title if notes signature was altered (e.g. "(WESS #12345)" or "#3516")
            if (!$wessApptId && !empty($event['title']) && preg_match('/#(\d+)/i', $event['title'], $matches)) {
                $wessApptId = $matches[1];
            }

            if (!$wessApptId) {
                continue;
            }

            // Check if already present in synced_appointments
            $existing = SyncedAppointment::where('location_id', $locationId)
                ->where('wess_appointment_id', (string) $wessApptId)
                ->first();

            $startTime = !empty($event['startTime']) ? Carbon::parse($event['startTime'])->toIso8601String() : null;
            $endTime   = !empty($event['endTime']) ? Carbon::parse($event['endTime'])->toIso8601String() : null;
            $status    = $event['appointmentStatus'] ?? ($event['status'] ?? 'confirmed');
            $syncHash  = md5(($startTime ?? '') . $status . ($event['title'] ?? ''));

            if (!$existing) {
                SyncedAppointment::create([
                    'location_id'         => $locationId,
                    'wess_appointment_id' => (string) $wessApptId,
                    'ghl_appointment_id'  => (string) $event['id'],
                    'ghl_contact_id'      => !empty($event['contactId']) ? (string) $event['contactId'] : null,
                    'calendar_id'         => !empty($event['calendarId']) ? (string) $event['calendarId'] : null,
                    'assigned_user_id'    => !empty($event['assignedUserId']) ? (string) $event['assignedUserId'] : null,
                    'title'               => $event['title'] ?? '',
                    'status'              => (string) $status,
                    'start_time'          => $startTime,
                    'end_time'            => $endTime,
                    'sync_hash'           => $syncHash,
                    'last_synced_at'      => now(),
                ]);
                $reconciled++;
                Log::info("Self-Healing Layer 2: Reconciled existing CRM appointment {$event['id']} for WESS appointment {$wessApptId} in location {$locationId}");
            } else {
                // If GHL appointment ID was missing or changed, repair it
                if (empty($existing->ghl_appointment_id) || $existing->ghl_appointment_id !== (string) $event['id']) {
                    $existing->update([
                        'ghl_appointment_id' => (string) $event['id'],
                        'calendar_id'        => !empty($event['calendarId']) ? (string) $event['calendarId'] : $existing->calendar_id,
                        'assigned_user_id'   => !empty($event['assignedUserId']) ? (string) $event['assignedUserId'] : $existing->assigned_user_id,
                        'last_synced_at'     => now(),
                    ]);
                    $reconciled++;
                }
            }
        }

        return $reconciled;
    }

    /**
     * Real-time duplicate check for a single WESS appointment ID against CRM calendar
     */
    public function findExistingCrmEventByWessId(string $locationId, string|int $wessApptId, string $startTime, ?string $calendarId = null): ?string
    {
        try {
            $parsedStart = Carbon::parse($startTime);
            $searchEvents = $this->getCalendarEvents(
                $locationId, 
                $parsedStart->copy()->subHours(2), 
                $parsedStart->copy()->addHours(2), 
                $calendarId
            );

            foreach ($searchEvents as $ev) {
                if (!is_array($ev) || empty($ev['id'])) {
                    continue;
                }
                $evNotes = $ev['notes'] ?? ($ev['description'] ?? '');
                if (preg_match('/WESS\s+Booking\s+ID:\s*' . preg_quote((string) $wessApptId, '/') . '\b/i', $evNotes)) {
                    return (string) $ev['id'];
                }
                if (!empty($ev['title']) && str_contains($ev['title'], (string) $wessApptId)) {
                    return (string) $ev['id'];
                }
            }
        } catch (Exception $e) {
            Log::warning("Error in findExistingCrmEventByWessId: " . $e->getMessage());
        }

        return null;
    }
}
