<?php

namespace App\Services\Ghl;

use App\Models\WebhookLog;
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
    protected function ghlRequest(string $method, string $endpoint, string $locationId, array $data = [])
    {
        $token = $this->authService->getValidAccessToken($locationId);

        $client = Http::withHeaders([
            'Authorization' => "Bearer {$token}",
            'Version'       => config('ghl.api_version', 'v3'),
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
     * Find or create the main "WESS" calendar group / calendar in GHL
     * (Excel Item A.1 & A.2: set a main calendar group in MC for this, call it WESS)
     */
    public function getOrCreateWessCalendar(string $locationId): array
    {
        try {
            $res = $this->ghlRequest('GET', "calendars/?locationId={$locationId}", $locationId);

            if (in_array($res->status(), [200, 201])) {
                $calendars = $res->json('calendars') ?? [];
                foreach ($calendars as $cal) {
                    if (stripos($cal['name'] ?? '', 'WESS') !== false) {
                        return $cal;
                    }
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
    public function syncAppointmentToGhl(string $locationId, array $wessAppointment, bool $maskPrivateDetails = true): array
    {
        $calendar = $this->getOrCreateWessCalendar($locationId);
        $calendarId = $calendar['id'] ?? 'default_wess_calendar';

        $startTime = $wessAppointment['start_time'] ?? $wessAppointment['datetime'] ?? now()->toIso8601String();
        $endTime   = $wessAppointment['end_time'] ?? now()->addHour()->toIso8601String();
        $serviceName = $wessAppointment['service_name'] ?? $wessAppointment['service'] ?? 'Salon & Spa Therapy';

        // Privacy title masking as requested in Excel Item A.4
        $title = $maskPrivateDetails
            ? "{$serviceName} (Booked Slot)"
            : "WESS Appt: " . ($wessAppointment['customer_name'] ?? 'Client') . " - {$serviceName}";

        $payload = [
            'calendarId'    => $calendarId,
            'locationId'    => $locationId,
            'title'         => $title,
            'startTime'     => $startTime,
            'endTime'       => $endTime,
            'appointmentStatus' => 'confirmed',
            'assignedUserId'=> $wessAppointment['staff_id'] ?? null,
            'notes'         => "WESS Booking ID: " . ($wessAppointment['id'] ?? 'N/A'),
        ];

        try {
            $res = $this->ghlRequest('POST', 'calendars/events/appointments', $locationId, $payload);
            $statusCode = $res->status();

            WebhookLog::create([
                'source'          => 'wess_sync',
                'location_id'     => $locationId,
                'event_type'      => 'GhlCalendarEventCreated',
                'endpoint'        => "{$this->baseUrl}/calendars/events/appointments",
                'method'          => 'POST',
                'payload'         => $payload,
                'response_status' => $statusCode,
                'response_body'   => substr($res->body(), 0, 1000),
            ]);

            return $res->json() ?? ['status' => $statusCode];
        } catch (Exception $e) {
            Log::error("Failed to sync appointment to GHL calendar for location {$locationId}: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Update GHL Contact Custom Fields (Excel Item B.i: WESS Code & Last Visit)
     */
    public function updateContactWessFields(string $locationId, string $contactId, string $wessCode, ?string $lastVisit = null): bool
    {
        $customFields = [
            [
                'id'    => 'wess_customer_code',
                'key'   => 'wess_customer_code',
                'field_value' => $wessCode,
            ]
        ];

        if ($lastVisit) {
            $customFields[] = [
                'id'    => 'wess_last_visit',
                'key'   => 'wess_last_visit',
                'field_value' => $lastVisit,
            ];
        }

        try {
            $res = $this->ghlRequest('PUT', "contacts/{$contactId}", $locationId, [
                'customFields' => $customFields,
            ]);

            return in_array($res->status(), [200, 201]);
        } catch (Exception $e) {
            Log::warning("Could not update contact custom fields in GHL: " . $e->getMessage());
            return false;
        }
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
