<?php

namespace App\Services\Wess;

use App\Models\SyncedAppointment;
use App\Models\WessConfig;
use App\Models\WebhookLog;
use App\Services\Ghl\GhlCalendarService;
use Illuminate\Support\Facades\Log;
use Exception;

class WessSyncService
{
    protected GhlCalendarService $ghlCalendarService;

    public function __construct(GhlCalendarService $ghlCalendarService)
    {
        $this->ghlCalendarService = $ghlCalendarService;
    }

    /**
     * Run synchronization for all active locations or a specific location
     * Respects Master Sync Switch (Excel Item A.3)
     */
    public function syncAllLocations(?string $specificLocationId = null, ?int $limit = null, bool $isTest = false): array
    {
        $query = WessConfig::query();

        if ($specificLocationId) {
            $query->where('location_id', $specificLocationId);
        }

        $configs = $query->get();
        $results = [];

        foreach ($configs as $config) {
            // Excel Item A.3: Master Switch check
            if (!$config->is_sync_enabled) {
                $results[$config->location_id] = [
                    'status'  => 'skipped',
                    'message' => 'Sync is paused for this location via Master Switch.',
                ];
                continue;
            }

            if (empty($config->api_token)) {
                $results[$config->location_id] = [
                    'status'  => 'skipped',
                    'message' => 'WESS API token not configured.',
                ];
                continue;
            }

            $results[$config->location_id] = $this->syncLocation($config, $limit, $isTest);
        }

        return $results;
    }

    /**
     * Synchronize a single location between WESS and GoHighLevel
     */
    public function syncLocation(WessConfig $config, ?int $limit = null, bool $isTest = false): array
    {
        $locationId = $config->location_id;
        $branchId   = $config->branch_id ?: 1;
        $wessClient = new WessClient($config->api_token, $config->base_url);

        $syncedCount  = 0;
        $skippedCount = 0;
        $errors       = [];

        try {
            // Live check: Ensure sync has not been paused before fetching appointments
            $freshConfig = WessConfig::where('location_id', $locationId)->first();
            if (!$freshConfig || !$freshConfig->is_sync_enabled || !$freshConfig->is_connected) {
                return [
                    'status'  => 'skipped',
                    'message' => 'Sync is paused for this location via Master Switch.',
                ];
            }

            // 1. Fetch upcoming appointments for the next 30 days from WESS (per official WESS API Doc section 3.12)
            $params = [
                'filter' => [
                    'date_from' => now()->startOfDay()->format('Y-m-d H:i:s'),
                    'date_to'   => now()->addDays(30)->endOfDay()->format('Y-m-d H:i:s'),
                ],
                'per_page' => $limit ? min($limit, 100) : 100,
                'sort'     => 'date',
            ];
            $appointments = $wessClient->getAppointments($branchId, $params, true, $limit);

            // Cap appointments if limit is set (e.g. ?test=1 or ?test=5 to test first 1 or 5 real records)
            if ($limit !== null && $limit > 0 && !empty($appointments)) {
                $appointments = array_slice($appointments, 0, $limit);
            }

            $stoppedEarly = false;

            // Pre-fetch therapist calendar map once before the loop to protect CRM API rate limits
            $therapistMap = !empty($appointments)
                ? $this->ghlCalendarService->getTherapistCalendarMap($locationId, $config->company_id)
                : [];

            // 2. Sync appointments to CRM calendar (Excel Item A.1 & A.4)
            // If appointments is empty (0 appointments in WESS), loop does not run and CRM is NOT called!
            foreach ($appointments as $appt) {
                // Live check: Re-verify sync switch directly from database before processing EACH appointment
                // If the user flips the Master Switch to "Sync Paused" on the custom page, terminate loop immediately!
                $liveConfig = WessConfig::where('location_id', $locationId)->first();
                if (!$liveConfig || !$liveConfig->is_sync_enabled || !$liveConfig->is_connected) {
                    $stoppedEarly = true;
                    Log::info("WESS sync terminated immediately for location {$locationId}: Master sync switch was paused from custom page.");
                    break;
                }

                try {
                    $apptId = $appt['id'] ?? null;
                    if (!$apptId) {
                        continue;
                    }

                    $apptHash = md5(($appt['date'] ?? '') . ($appt['status'] ?? '') . ($appt['processing_time'] ?? '') . ($appt['service_name'] ?? $appt['service'] ?? '') . ($appt['customer_name'] ?? ''));

                    // Check persistent database table for existing sync mapping (never relies on volatile cache)
                    $existingSync = SyncedAppointment::where('location_id', $locationId)
                        ->where('wess_appointment_id', (string) $apptId)
                        ->first();

                    // Skip unchanged appointments to conserve API quota and eliminate duplicates
                    if (!$isTest && $existingSync && $existingSync->ghl_appointment_id && $existingSync->sync_hash === $apptHash) {
                        $skippedCount++;
                        continue;
                    }

                    // Dynamically resolve therapist personal calendar for this appointment
                    // If this appointment was already tracked on a specific CRM calendar, preserve it as the primary fallback
                    $fallbackCalId  = $existingSync?->calendar_id ?: ($liveConfig->calendar_id ?: null);
                    $fallbackUserId = $existingSync?->assigned_user_id ?: ($liveConfig->calendar_user_id ?: null);

                    $resolved = $this->ghlCalendarService->resolveTherapistCalendar(
                        $therapistMap,
                        $appt,
                        $fallbackCalId,
                        $fallbackUserId
                    );

                    $this->ghlCalendarService->syncAppointmentToGhl(
                        $locationId, 
                        $appt, 
                        true, // Mask private details for public slot view (Excel Item A.4)
                        $resolved['calendar_id'] ?: $fallbackCalId,
                        $resolved['user_id'] ?: $fallbackUserId
                    );
                    $syncedCount++;

                    // Pace calls to GHL to respect GHL 10 req/s burst limit
                    usleep(120000); // 120ms
                } catch (Exception $e) {
                    $errors[] = "Appt #" . ($appt['id'] ?? 'unknown') . ": " . $e->getMessage();
                }
            }

            // Update last_synced_at timestamp on location config
            WessConfig::where('location_id', $locationId)->update(['last_synced_at' => now()]);

            if ($stoppedEarly) {
                $summaryMessage = "Sync stopped early: Master sync switch was toggled off via custom page (synced {$syncedCount} appointment(s) before stopping).";
            } else {
                $summaryMessage = $syncedCount > 0
                    ? "Synced {$syncedCount} new/updated appointment(s)."
                    : ($skippedCount > 0 ? "All {$skippedCount} appointments are already up to date in CRM calendar (verified in database, 0 duplicates)." : "No appointments found.");
            }

            $responseBodyData = [
                'synced_count'      => $syncedCount,
                'skipped_unchanged' => $skippedCount,
                'stopped_early'     => $stoppedEarly,
                'message'           => $summaryMessage,
                'errors'            => $errors,
            ];

            WebhookLog::create([
                'source'          => 'wess_sync',
                'location_id'     => $locationId,
                'event_type'      => $stoppedEarly ? 'WessSyncStoppedByUser' : ($limit ? 'TestWessSyncCompleted' : 'PeriodicWessSyncCompleted'),
                'endpoint'        => "{$config->base_url}/branches/{$branchId}/appointments",
                'method'          => 'GET',
                'payload'         => array_merge(['branch_id' => $branchId, 'total_fetched' => count($appointments)], $params),
                'response_status' => 200,
                'response_body'   => json_encode($responseBodyData),
            ]);

            return [
                'status'            => $stoppedEarly ? 'stopped' : 'success',
                'synced_count'      => $syncedCount,
                'skipped_unchanged' => $skippedCount,
                'stopped_early'     => $stoppedEarly,
                'message'           => $summaryMessage,
                'errors'            => $errors,
            ];
        } catch (Exception $e) {
            Log::error("Failed WESS sync for location {$locationId}: " . $e->getMessage());

            WebhookLog::create([
                'source'          => 'wess_sync',
                'location_id'     => $locationId,
                'event_type'      => 'PeriodicWessSyncFailed',
                'error_message'   => $e->getMessage(),
                'response_status' => 500,
            ]);

            return [
                'status'  => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }
}
