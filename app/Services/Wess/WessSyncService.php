<?php

namespace App\Services\Wess;

use App\Models\SyncedAppointment;
use App\Models\WebhookLog;
use App\Models\WessConfig;
use App\Services\Ghl\GhlCalendarService;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;

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
            if (! $config->is_sync_enabled) {
                $results[$config->location_id] = [
                    'status' => 'skipped',
                    'message' => 'Sync is paused for this location via Master Switch.',
                ];

                continue;
            }

            if (empty($config->api_token)) {
                $results[$config->location_id] = [
                    'status' => 'skipped',
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
    public function syncLocation(WessConfig|string $config, ?int $limit = null, bool $isTest = false): array
    {
        if (is_string($config)) {
            $config = WessConfig::where('location_id', $config)->firstOrFail();
        }

        $locationId = $config->location_id;
        $branchId = $config->branch_id ?: 1;
        $wessClient = new WessClient($config->api_token, $config->base_url);

        $syncedCount = 0;
        $skippedCount = 0;
        $cancelledCount = 0;
        $errors = [];

        try {
            // Live check: Ensure sync has not been paused before fetching appointments
            $freshConfig = WessConfig::where('location_id', $locationId)->first();
            if (! $freshConfig || ! $freshConfig->is_sync_enabled || ! $freshConfig->is_connected) {
                return [
                    'status' => 'skipped',
                    'message' => 'Sync is paused for this location via Master Switch.',
                ];
            }

            // 1. Determine date range for sync
            // If the sync was paused by the user, initiate a catch-up recovery sync starting from sync_paused_at
            $isCatchUp = false;
            $targetEndDate = now()->addDays(30)->endOfDay();

            if (! empty($freshConfig->sync_paused_at)) {
                $isCatchUp = true;
                $startDate = Carbon::parse($freshConfig->sync_paused_at)->startOfDay();
                Log::info("Catch-up recovery sync initiated for location {$locationId}: fetching missed appointments from {$startDate->toDateTimeString()} to {$targetEndDate->toDateTimeString()}.");
            } else {
                $startDate = now()->startOfDay();
            }

            // Fetch appointments from WESS
            // WESS API imposes a 30-day window limit per request (Section 3.12).
            // If the total window exceeds 30 days (e.g. paused for multiple weeks), chunk into consecutive 30-day slices.
            $appointments = [];
            $currentStart = $startDate->copy();
            $chunkCount = 0;

            while ($currentStart->lte($targetEndDate)) {
                $currentEnd = $currentStart->copy()->addDays(30)->endOfDay();
                if ($currentEnd->gt($targetEndDate)) {
                    $currentEnd = $targetEndDate->copy();
                }

                $chunkParams = [
                    'filter' => [
                        'date_from' => $currentStart->format('Y-m-d H:i:s'),
                        'date_to' => $currentEnd->format('Y-m-d H:i:s'),
                        'status' => 'OP,RQ,CF,CI,CO,LM,BC,NS,VD',
                    ],
                    'per_page' => $limit ? min($limit, 100) : 100,
                    'sort' => 'date',
                ];

                $chunkAppointments = $wessClient->getAppointments($branchId, $chunkParams, true, $limit);
                if (! empty($chunkAppointments)) {
                    $appointments = array_merge($appointments, $chunkAppointments);
                }

                $chunkCount++;

                // If test limit was reached, stop chunking
                if ($limit !== null && $limit > 0 && count($appointments) >= $limit) {
                    $appointments = array_slice($appointments, 0, $limit);
                    break;
                }

                // Advance to next chunk slice
                $currentStart = $currentEnd->copy()->addSecond()->startOfDay();
            }

            // Deduplicate by ID in case chunk boundaries overlapped
            if (! empty($appointments)) {
                $uniqueMap = [];
                foreach ($appointments as $a) {
                    if (! empty($a['id'])) {
                        $uniqueMap[$a['id']] = $a;
                    }
                }
                $appointments = array_values($uniqueMap);
            }

            // Cap appointments if limit is set (e.g. ?test=1 or ?test=5)
            if ($limit !== null && $limit > 0 && ! empty($appointments)) {
                $appointments = array_slice($appointments, 0, $limit);
            }

            $params = [
                'filter' => [
                    'date_from' => $startDate->format('Y-m-d H:i:s'),
                    'date_to' => $targetEndDate->format('Y-m-d H:i:s'),
                ],
                'is_catch_up' => $isCatchUp,
                'chunks' => $chunkCount,
                'per_page' => $limit ? min($limit, 100) : 100,
                'sort' => 'date',
            ];

            $stoppedEarly = false;

            // Pre-fetch therapist calendar map once before the loop to protect CRM API rate limits
            $therapistMap = ! empty($appointments)
                ? $this->ghlCalendarService->getTherapistCalendarMap($locationId, $config->company_id)
                : [];

            // Self-Healing Layer 2: Auto-reconcile existing CRM calendar events
            // If synced_appointments was ever wiped (e.g. after reinstall/reconnect), this rebuilds the links
            // from existing CRM calendar events so they are updated/skipped, preventing duplicate bookings.
            if (! empty($appointments)) {
                $dateFrom = $params['filter']['date_from'];
                $dateTo = $params['filter']['date_to'];
                $this->ghlCalendarService->reconcileExistingCrmEvents($locationId, $dateFrom, $dateTo, $config->company_id);
            }

            // 2. Sync appointments to CRM calendar (Excel Item A.1 & A.4)
            // If appointments is empty (0 appointments in WESS), loop does not run and CRM is NOT called!
            foreach ($appointments as $appt) {
                // Live check: Re-verify sync switch directly from database before processing EACH appointment
                // If the user flips the Master Switch to "Sync Paused" on the custom page, terminate loop immediately!
                $liveConfig = WessConfig::where('location_id', $locationId)->first();
                if (! $liveConfig || ! $liveConfig->is_sync_enabled || ! $liveConfig->is_connected) {
                    $stoppedEarly = true;
                    Log::info("WESS sync terminated immediately for location {$locationId}: Master sync switch was paused from custom page.");
                    break;
                }

                try {
                    $apptId = $appt['id'] ?? null;
                    if (! $apptId) {
                        continue;
                    }

                    // Check persistent database table for existing sync mapping (never relies on volatile cache)
                    $existingSync = SyncedAppointment::where('location_id', $locationId)
                        ->where('wess_appointment_id', (string) $apptId)
                        ->first();

                    // Dynamic cancellation detection from WESS status
                    // Handles explicit cancellations (LM / Last Minute Cancel, BC / Booking Cancelled, NS / No Show, VD / Void)
                    $statusLower = strtolower(trim((string) ($appt['status'] ?? '')));
                    $isCancelledInWess = in_array($statusLower, [
                        'cancelled', 'cancel', 'canceled',
                        'last minute cancel', 'last-minute cancel', 'lm',
                        'booking cancelled', 'booking cancel', 'bc',
                        'no show', 'no-show', 'ns',
                        'void', 'vd',
                    ], true);

                    if ($isCancelledInWess) {
                        if ($existingSync && $existingSync->status !== 'cancelled') {
                            if (! empty($existingSync->ghl_appointment_id)) {
                                // Reschedule Safeguard:
                                // If this appointment was rescheduled in GHL, a newer active WESS booking is linked to the same GHL appointment.
                                // We must NEVER cancel the GHL appointment in this scenario!
                                $hasActiveReschedule = SyncedAppointment::where('location_id', $locationId)
                                    ->where('ghl_appointment_id', $existingSync->ghl_appointment_id)
                                    ->where('wess_appointment_id', '!=', (string) $apptId)
                                    ->where('status', '!=', 'cancelled')
                                    ->exists();

                                if ($hasActiveReschedule) {
                                    Log::info("WESS cancellation sync: WESS #{$apptId} is marked '{$appt['status']}', but GHL appointment {$existingSync->ghl_appointment_id} is active under a rescheduled WESS booking. Preserved GHL appointment.");
                                } else {
                                    $cancelSuccess = $this->ghlCalendarService->cancelAppointmentInGhl($locationId, $existingSync->ghl_appointment_id);
                                    $cancelledCount++;
                                    Log::info("WESS cancellation propagated to CRM: WESS #{$apptId} is marked '{$appt['status']}'. Cancelled CRM event {$existingSync->ghl_appointment_id}.");

                                    // Record individual cancellation log with raw WESS appointment data
                                    WebhookLog::create([
                                        'source' => 'wess_sync',
                                        'location_id' => $locationId,
                                        'event_type' => 'WessAppointmentCancelled',
                                        'endpoint' => "{$this->ghlCalendarService->getBaseUrl()}/calendars/events/appointments/{$existingSync->ghl_appointment_id}",
                                        'method' => 'PUT',
                                        'payload' => [
                                            'ghl_appointment_id' => $existingSync->ghl_appointment_id,
                                            'wess_appointment_id' => (string) $apptId,
                                            'appointmentStatus' => 'cancelled',
                                            'status' => $appt['status'] ?? 'Cancelled',
                                            'wess_raw_appointment' => $appt,
                                        ],
                                        'response_status' => $cancelSuccess ? 200 : 400,
                                        'response_body' => json_encode([
                                            'status' => $cancelSuccess ? 'success' : 'failed',
                                            'appointmentStatus' => 'cancelled',
                                            'message' => "WESS appointment #{$apptId} is marked '{$appt['status']}'. Updated CRM appointment {$existingSync->ghl_appointment_id} status to 'cancelled'.",
                                            'wess_appointment_id' => (string) $apptId,
                                            'ghl_appointment_id' => $existingSync->ghl_appointment_id,
                                            'wess_raw_appointment' => $appt,
                                        ]),
                                    ]);
                                }
                            }
                            $existingSync->update(['status' => 'cancelled']);
                        } elseif (! $existingSync) {
                            // Layer 2 Recovery: If not in local mapping table, search GHL calendar for this WESS booking
                            $startTime = ! empty($appt['date']) ? Carbon::parse($appt['date'])->toIso8601String() : null;
                            $recoveredCrmId = $startTime ? $this->ghlCalendarService->findExistingCrmEventByWessId($locationId, (string) $apptId, $startTime) : null;

                            if ($recoveredCrmId) {
                                $cancelSuccess = $this->ghlCalendarService->cancelAppointmentInGhl($locationId, $recoveredCrmId);
                                $cancelledCount++;
                                SyncedAppointment::updateOrCreate(
                                    [
                                        'location_id' => $locationId,
                                        'wess_appointment_id' => (string) $apptId,
                                    ],
                                    [
                                        'ghl_appointment_id' => (string) $recoveredCrmId,
                                        'status' => 'cancelled',
                                        'start_time' => ! empty($appt['date']) ? Carbon::parse($appt['date'])->format('Y-m-d H:i:s') : null,
                                        'last_synced_at' => now(),
                                    ]
                                );

                                WebhookLog::create([
                                    'source' => 'wess_sync',
                                    'location_id' => $locationId,
                                    'event_type' => 'WessAppointmentCancelled',
                                    'endpoint' => "{$this->ghlCalendarService->getBaseUrl()}/calendars/events/appointments/{$recoveredCrmId}",
                                    'method' => 'PUT',
                                    'payload' => [
                                        'ghl_appointment_id' => $recoveredCrmId,
                                        'wess_appointment_id' => (string) $apptId,
                                        'appointmentStatus' => 'cancelled',
                                        'status' => $appt['status'] ?? 'Cancelled',
                                        'recovery_layer' => 2,
                                        'wess_raw_appointment' => $appt,
                                    ],
                                    'response_status' => $cancelSuccess ? 200 : 400,
                                    'response_body' => json_encode([
                                        'status' => $cancelSuccess ? 'success' : 'failed',
                                        'appointmentStatus' => 'cancelled',
                                        'message' => "Layer 2 Recovery: WESS appointment #{$apptId} marked '{$appt['status']}'. Updated CRM appointment {$recoveredCrmId} status to 'cancelled'.",
                                        'wess_appointment_id' => (string) $apptId,
                                        'ghl_appointment_id' => $recoveredCrmId,
                                        'wess_raw_appointment' => $appt,
                                    ]),
                                ]);
                            } else {
                                // Record the cancelled appointment locally so it's tracked and not treated as a new active booking
                                SyncedAppointment::updateOrCreate(
                                    [
                                        'location_id' => $locationId,
                                        'wess_appointment_id' => (string) $apptId,
                                    ],
                                    [
                                        'status' => 'cancelled',
                                        'start_time' => ! empty($appt['date']) ? Carbon::parse($appt['date'])->format('Y-m-d H:i:s') : null,
                                        'last_synced_at' => now(),
                                    ]
                                );
                            }
                        }

                        continue;
                    }

                    $apptHash = md5(($appt['date'] ?? '').($appt['status'] ?? '').($appt['processing_time'] ?? '').($appt['service_name'] ?? $appt['service'] ?? '').($appt['customer_name'] ?? ''));

                    // Skip unchanged appointments to conserve API quota and eliminate duplicates
                    if (! $isTest && $existingSync && $existingSync->ghl_appointment_id && $existingSync->sync_hash === $apptHash) {
                        $skippedCount++;

                        continue;
                    }

                    // Dynamically resolve therapist personal calendar for this appointment
                    // If this appointment was already tracked on a specific CRM calendar, preserve it as the primary fallback
                    $fallbackCalId = $existingSync?->calendar_id ?: ($liveConfig->calendar_id ?: null);
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
                    $errors[] = 'Appt #'.($appt['id'] ?? 'unknown').': '.$e->getMessage();
                }
            }

            /*
            // [COMMENTED OUT PER USER REQUEST - October 27, 2026]
            // 3. Sync Cancellations: Remove or cancel appointments in CRM that were cancelled or deleted in WESS
            if (!$stoppedEarly && !$isTest) {
                $activeWessIds = array_map('strval', array_column(
                    array_filter($appointments, function($a) {
                        $st = strtolower(trim((string)($a['status'] ?? '')));
                        return !in_array($st, ['cancelled', 'cancel', 'last minute cancel', 'bc'], true);
                    }),
                    'id'
                ));

                $disappearedAppts = SyncedAppointment::where('location_id', $locationId)
                    ->where('status', '!=', 'cancelled')
                    ->get()
                    ->filter(function ($rec) use ($startDate, $targetEndDate) {
                        if (empty($rec->start_time)) return false;
                        try {
                            $parsed = Carbon::parse($rec->start_time);
                            return $parsed->gte($startDate) && $parsed->lte($targetEndDate);
                        } catch (\Throwable $e) {
                            return false;
                        }
                    });

                foreach ($disappearedAppts as $disAppt) {
                    if (!in_array((string)$disAppt->wess_appointment_id, $activeWessIds, true)) {
                        if (!empty($disAppt->ghl_appointment_id)) {
                            $this->ghlCalendarService->cancelAppointmentInGhl($locationId, $disAppt->ghl_appointment_id);
                        }
                        $disAppt->update(['status' => 'cancelled']);
                        Log::info("WESS cancellation propagated to CRM: WESS #{$disAppt->wess_appointment_id} is no longer active in WESS. Cancelled corresponding CRM appointment {$disAppt->ghl_appointment_id}.");
                    }
                }
            }
            */

            // Update last_synced_at timestamp on location config
            // If this was a catch-up recovery sync and completed without being stopped early, clear sync_paused_at
            $updateConfig = ['last_synced_at' => now()];
            if (! $stoppedEarly && ! empty($freshConfig->sync_paused_at)) {
                $updateConfig['sync_paused_at'] = null;
                Log::info("Catch-up recovery sync successfully completed for location {$locationId}. Reset sync_paused_at to null.");
            }
            WessConfig::where('location_id', $locationId)->update($updateConfig);

            if ($stoppedEarly) {
                $summaryMessage = "Sync stopped early: Master sync switch was toggled off via custom page (synced {$syncedCount} appointment(s) before stopping).";
            } else {
                $summaryMessage = $syncedCount > 0
                    ? "Synced {$syncedCount} new/updated appointment(s)."
                    : ($skippedCount > 0 ? "All {$skippedCount} appointments are already up to date in CRM calendar (verified in database, 0 duplicates)." : 'No appointments found.');
                if ($cancelledCount > 0) {
                    $summaryMessage .= " Cancelled {$cancelledCount} appointment(s).";
                }
            }

            $responseBodyData = [
                'synced_count' => $syncedCount,
                'skipped_unchanged' => $skippedCount,
                'cancelled_count' => $cancelledCount,
                'stopped_early' => $stoppedEarly,
                'message' => $summaryMessage,
                'wess_raw_appointments' => $appointments,
                'errors' => $errors,
            ];

            WebhookLog::create([
                'source' => 'wess_sync',
                'location_id' => $locationId,
                'event_type' => $stoppedEarly ? 'WessSyncStoppedByUser' : ($limit ? 'TestWessSyncCompleted' : 'PeriodicWessSyncCompleted'),
                'endpoint' => "{$config->base_url}/branches/{$branchId}/appointments",
                'method' => 'GET',
                'payload' => array_merge(['branch_id' => $branchId, 'total_fetched' => count($appointments)], $params),
                'response_status' => 200,
                'response_body' => json_encode($responseBodyData),
            ]);

            return [
                'status' => $stoppedEarly ? 'stopped' : 'success',
                'synced_count' => $syncedCount,
                'skipped_unchanged' => $skippedCount,
                'cancelled_count' => $cancelledCount,
                'stopped_early' => $stoppedEarly,
                'message' => $summaryMessage,
                'errors' => $errors,
            ];
        } catch (Exception $e) {
            Log::error("Failed WESS sync for location {$locationId}: ".$e->getMessage());

            WebhookLog::create([
                'source' => 'wess_sync',
                'location_id' => $locationId,
                'event_type' => 'PeriodicWessSyncFailed',
                'error_message' => $e->getMessage(),
                'response_status' => 500,
            ]);

            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }
}
