<?php

namespace App\Services\Wess;

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
    public function syncAllLocations(?string $specificLocationId = null): array
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

            $results[$config->location_id] = $this->syncLocation($config);
        }

        return $results;
    }

    /**
     * Synchronize a single location between WESS and GoHighLevel
     */
    public function syncLocation(WessConfig $config): array
    {
        $locationId = $config->location_id;
        $branchId   = $config->branch_id ?: 1;
        $wessClient = new WessClient($config->api_token, $config->base_url);

        $syncedCount = 0;
        $errors      = [];

        try {
            // 1. Fetch upcoming appointments from WESS (Excel Item A.1)
            $appointments = $wessClient->getAppointments($branchId);

            // 2. Sync appointments to GHL calendar (Excel Item A.1 & A.4)
            foreach ($appointments as $appt) {
                try {
                    $this->ghlCalendarService->syncAppointmentToGhl(
                        $locationId, 
                        $appt, 
                        true // Mask private details for public slot view (Excel Item A.4)
                    );
                    $syncedCount++;
                } catch (Exception $e) {
                    $errors[] = "Appt #{$appt['id']}: " . $e->getMessage();
                }
            }

            WebhookLog::create([
                'source'          => 'wess_sync',
                'location_id'     => $locationId,
                'event_type'      => 'PeriodicWessSyncCompleted',
                'endpoint'        => "{$config->base_url}/branches/{$branchId}/appointments",
                'method'          => 'GET',
                'payload'         => ['branch_id' => $branchId, 'total_fetched' => count($appointments)],
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
