<?php

namespace App\Services\Wess;

use App\Models\WessConfig;
use App\Models\WebhookLog;
use App\Services\Ghl\GhlCalendarService;
use Illuminate\Support\Facades\Cache;
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

        $syncedCount = 0;
        $errors      = [];

        try {
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

            // 2. Sync appointments to GHL calendar (Excel Item A.1 & A.4)
            // If appointments is empty (0 appointments in WESS), loop does not run and GHL is NOT called!
            foreach ($appointments as $appt) {
                try {
                    $apptId = $appt['id'] ?? null;
                    $apptHash = md5(($appt['date'] ?? '') . ($appt['status'] ?? '') . ($appt['processing_time'] ?? ''));
                    $cacheKey = "wess_synced_{$locationId}_{$apptId}_{$apptHash}";

                    // Skip unchanged appointments to conserve API quota and avoid redundant syncs
                    if (!$isTest && $apptId && Cache::has($cacheKey)) {
                        continue;
                    }

                    $this->ghlCalendarService->syncAppointmentToGhl(
                        $locationId, 
                        $appt, 
                        true, // Mask private details for public slot view (Excel Item A.4)
                        $config->calendar_id ?: null,
                        $config->calendar_user_id ?: null
                    );
                    $syncedCount++;

                    if ($apptId) {
                        Cache::put($cacheKey, true, now()->addDays(7));
                    }

                    // Pace calls to GHL to respect GHL 10 req/s burst limit
                    usleep(120000); // 120ms
                } catch (Exception $e) {
                    $errors[] = "Appt #" . ($appt['id'] ?? 'unknown') . ": " . $e->getMessage();
                }
            }

            WebhookLog::create([
                'source'          => 'wess_sync',
                'location_id'     => $locationId,
                'event_type'      => $limit ? 'TestWessSyncCompleted' : 'PeriodicWessSyncCompleted',
                'endpoint'        => "{$config->base_url}/branches/{$branchId}/appointments",
                'method'          => 'GET',
                'payload'         => array_merge(['branch_id' => $branchId, 'total_fetched' => count($appointments)], $params),
                'response_status' => 200,
                'response_body'   => json_encode(['synced_count' => $syncedCount, 'errors' => $errors]),
            ]);

            return [
                'status'       => 'success',
                'synced_count' => $syncedCount,
                'errors'       => $errors,
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
