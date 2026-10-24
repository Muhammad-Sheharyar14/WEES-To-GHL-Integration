<?php

namespace App\Http\Controllers;

use App\Models\GhlToken;
use App\Models\SyncedAppointment;
use App\Models\WessConfig;
use App\Models\WebhookLog;
use App\Services\Ghl\GhlCalendarService;
use App\Services\Wess\WessClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Exception;

class GhlWebhookController extends Controller
{
    /**
     * Handle GoHighLevel Marketplace App Webhooks:
     * - AppInstall / INSTALL (Location or Agency Level)
     * - AppUninstall / UNINSTALL (Clean up credentials, tokens & logs)
     * - AppointmentCreate, AppointmentUpdate, AppointmentDelete / Cancel (2-way sync)
     * - ContactCreate, ContactUpdate, ContactDelete (Customer sync & field mapping)
     * POST /webhook & POST /api/webhook
     */
    public function ghlWebhook(Request $request)
    {
        $payload = $request->all();
        $headers = $request->headers->all();

        $type       = $payload['type'] ?? $payload['event'] ?? ($payload['eventType'] ?? '');
        $locationId = $payload['locationId'] ?? $payload['location_id'] ?? ($payload['extras']['locationId'] ?? null);
        $companyId  = $payload['companyId'] ?? $payload['company_id'] ?? ($payload['extras']['companyId'] ?? null);

        Log::info('Received GHL Marketplace Webhook', [
            'type'        => $type,
            'location_id' => $locationId,
            'company_id'  => $companyId,
            'payload'     => $payload,
        ]);

        $log = WebhookLog::create([
            'source'          => 'ghl_webhook',
            'location_id'     => $locationId,
            'event_type'      => $type ?: 'ghl_webhook',
            'endpoint'        => $request->fullUrl(),
            'method'          => $request->method(),
            'headers'         => $headers,
            'payload'         => $payload,
            'response_status' => 200,
        ]);

        $normalizedType = strtolower(str_replace(['_', '-'], '', (string)$type));

        // 1. App Uninstall / Disconnect Lifecycle: Clean up credentials, tokens and logs
        // Per official GHL docs: type is "UNINSTALL" or "AppUninstall"
        if (in_array($normalizedType, ['appuninstall', 'appuninstalled', 'locationdisconnected', 'locationdeleted', 'uninstall', 'uninstalled', 'disconnect'])) {
            if (!empty($locationId)) {
                $this->cleanupLocation($locationId);
            } elseif (!empty($companyId)) {
                $this->cleanupCompany($companyId);
            }

            $log->update([
                'response_body' => json_encode(['status' => 'success', 'message' => 'Uninstalled and data cleaned up.']),
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'GHL app uninstalled and credentials/tokens/logs cleaned up successfully.',
            ], 200);
        }

        // 2. App Install / Re-connect Lifecycle
        // Per official GHL docs: type is "INSTALL" or "AppInstall"
        if (in_array($normalizedType, ['install', 'appinstall', 'appinstalled', 'locationconnected'])) {
            if (!empty($locationId)) {
                GhlToken::where('location_id', $locationId)->update(['is_active' => true]);
                Log::info("Reactivated GHL token on INSTALL event for location: {$locationId}");
            }
            if (!empty($companyId)) {
                GhlToken::where('company_id', $companyId)->update(['is_active' => true]);
                Log::info("Reactivated GHL company token on INSTALL event for company: {$companyId}");
            }

            $log->update([
                'response_body' => json_encode(['status' => 'success', 'message' => 'Install acknowledged.']),
            ]);

            return response()->json([
                'status'  => 'success',
                'message' => 'GHL app install received and acknowledged.',
            ], 200);
        }

        // 3. Appointment Lifecycle Webhooks (AppointmentCreate, AppointmentUpdate, AppointmentDelete)
        if (str_contains($normalizedType, 'appointment')) {
            $this->handleAppointmentWebhook($locationId, $normalizedType, $payload, $log);
            return response()->json([
                'status'  => 'success',
                'message' => 'Appointment webhook processed.',
            ], 200);
        }

        // 4. Contact Lifecycle Webhooks (ContactCreate, ContactUpdate, ContactDelete)
        if (str_contains($normalizedType, 'contact')) {
            $this->handleContactWebhook($locationId, $normalizedType, $payload, $log);
            return response()->json([
                'status'  => 'success',
                'message' => 'Contact webhook processed.',
            ], 200);
        }


        // 5. Default Fallback
        return response()->json([
            'status'  => 'success',
            'message' => 'GHL Webhook received and logged.',
        ], 200);
    }

    /**
     * Handle Appointment Webhooks from GoHighLevel
     * Schema: appointment { id, contactId, calendarId, startTime, endTime, appointmentStatus, notes, title }
     */
    public function handleAppointmentWebhook(?string $locationId, string $type, array $payload, WebhookLog $log): void
    {
        try {
            if (empty($locationId)) {
                $log->update([
                    'response_status' => 200,
                    'error_message'   => 'Missing location_id in appointment webhook payload',
                    'response_body'   => json_encode(['status' => 'skipped', 'message' => 'Missing location_id in webhook payload']),
                ]);
                return;
            }

            $config = WessConfig::where('location_id', $locationId)->first();
            if (!$config || empty($config->api_token)) {
                $log->update([
                    'error_message'   => 'WESS credentials not configured for this location.',
                    'response_body'   => 'WESS not configured',
                    'response_status' => 200,
                ]);
                return;
            }

            if (!$config->is_sync_enabled) {
                $log->update([
                    'response_body'   => 'Master sync is paused for this location. Skipping WESS booking push.',
                    'response_status' => 200,
                ]);
                return;
            }

            $wessClient = new WessClient($config->api_token, $config->base_url);
            $branchId   = $config->branch_id ?: 1;

            // Appointment object per official GHL docs schema
            $appointment   = $payload['appointment'] ?? $payload;
            $appointmentId = $appointment['id'] ?? $payload['appointmentId'] ?? ($payload['id'] ?? null);
            $contactId     = $appointment['contactId'] ?? $payload['contactId'] ?? null;
            $status        = strtolower($appointment['appointmentStatus'] ?? $payload['appointmentStatus'] ?? '');
            $notes         = (string)($appointment['notes'] ?? $payload['notes'] ?? ($payload['appointment']['notes'] ?? ''));
            $title         = (string)($appointment['title'] ?? $payload['title'] ?? ($payload['appointment']['title'] ?? ''));
            $startTime     = $appointment['startTime'] ?? $payload['startTime'] ?? ($payload['appointmentStartTime'] ?? null);
            $phone         = $appointment['phone'] ?? $payload['phone'] ?? ($payload['contact']['phone'] ?? '');
            $firstName     = $appointment['firstName'] ?? $payload['firstName'] ?? ($payload['contact']['firstName'] ?? '');
            $lastName      = $appointment['lastName'] ?? $payload['lastName'] ?? ($payload['contact']['lastName'] ?? '');
            $email         = $appointment['email'] ?? $payload['email'] ?? ($payload['contact']['email'] ?? '');

            // Echo Loop Prevention (3-Tier Protection):
            // When WESS sync pushes an appointment to GHL, GHL triggers AppointmentCreate / AppointmentUpdate.
            // We prevent feedback loops through 3 layers:
            // 1. Debounce Cache Lock: 45s TTL set when WESS pushes to GHL.
            // 2. Metadata Signature: If notes contain 'WESS Booking ID:' or title contains WESS tag.
            // 3. Database Check: If this GHL appointment is already linked to a WESS booking in synced_appointments.
            $isCancellation = str_contains($type, 'delete') || str_contains($type, 'cancel') || in_array($status, ['cancelled', 'canceled', 'deleted']);
            if (!$isCancellation) {
                // Layer 1: 45s Cache Debounce Lock
                if ($appointmentId && Cache::has("wess_origin_appt_{$appointmentId}")) {
                    Log::info("Loop prevention: Skipped appointment webhook {$appointmentId} due to active 45s sync debounce lock.");
                    $log->update([
                        'response_status' => 200,
                        'response_body'   => json_encode([
                            'status'  => 'skipped',
                            'message' => 'Loop prevention: Appointment originated from recent WESS sync. No re-sync needed.',
                        ]),
                    ]);
                    return;
                }

                // Layer 2: WESS Metadata Signature Check (in notes or title)
                if (
                    str_contains($notes, 'WESS Booking ID:') ||
                    str_contains($notes, 'WESS ID:') ||
                    str_contains($title, '(Booked Slot)') ||
                    str_starts_with($title, 'WESS Appt:')
                ) {
                    Log::info("Loop prevention: Skipped appointment webhook {$appointmentId} as it contains WESS sync signature.");
                    $log->update([
                        'response_status' => 200,
                        'response_body'   => json_encode([
                            'status'  => 'skipped',
                            'message' => 'Loop prevention: Appointment contains WESS sync signature. Skipping duplicate creation in WESS.',
                        ]),
                    ]);
                    return;
                }
                // Layer 3 (Contextual): Note that database mapping check & change detection
                // are performed contextually before booking, allowing legitimate CRM appointment updates/rescheduling.
            }

            // Cancellation / Deletion Event
            if (str_contains($type, 'delete') || str_contains($type, 'cancel') || in_array($status, ['cancelled', 'canceled', 'deleted'])) {
                if ($appointmentId) {
                    $synced = SyncedAppointment::where('location_id', $locationId)
                        ->where('ghl_appointment_id', (string) $appointmentId)
                        ->first();

                    $wessApptId = $synced?->wess_appointment_id;

                    // Fallback 1: Extract WESS Booking ID from notes or title
                    if (!$wessApptId) {
                        $rawNotes = (string) ($appointment['notes'] ?? $payload['notes'] ?? ($payload['appointment']['notes'] ?? ''));
                        $rawTitle = (string) ($appointment['title'] ?? $payload['title'] ?? ($payload['appointment']['title'] ?? ''));

                        if (preg_match('/WESS\s+Booking\s+ID:\s*([0-9a-zA-Z_-]+)/i', $rawNotes, $m)) {
                            $wessApptId = $m[1];
                            Log::info("Cancellation Auto-Recovery: Found WESS Booking ID {$wessApptId} in appointment notes.");
                        } elseif (preg_match('/#(\d{5,})/i', $rawTitle, $m)) {
                            $wessApptId = $m[1];
                            Log::info("Cancellation Auto-Recovery: Found WESS Booking ID {$wessApptId} in appointment title.");
                        } elseif (is_numeric($appointmentId) || preg_match('/^appt_(\d+)$/', (string)$appointmentId)) {
                            $wessApptId = (string) $appointmentId;
                        }
                    }

                    // Fallback 2: If notes/title was missing from payload, query CRM calendar events
                    if (!$wessApptId) {
                        try {
                            $ghlCalendarService = app(GhlCalendarService::class);
                            $dateFrom = !empty($startTime) ? Carbon::parse($startTime)->subDays(1) : now()->subDays(7);
                            $dateTo   = !empty($startTime) ? Carbon::parse($startTime)->addDays(1) : now()->addDays(30);
                            $candidateEvents = $ghlCalendarService->getCalendarEvents($locationId, $dateFrom, $dateTo);

                            foreach ($candidateEvents as $ev) {
                                if (($ev['id'] ?? '') === (string) $appointmentId) {
                                    $evNotes = $ev['notes'] ?? ($ev['description'] ?? '');
                                    $evTitle = $ev['title'] ?? '';
                                    if (preg_match('/WESS\s+Booking\s+ID:\s*([0-9a-zA-Z_-]+)/i', $evNotes, $m)) {
                                        $wessApptId = $m[1];
                                        Log::info("Cancellation Auto-Recovery: Found WESS Booking ID {$wessApptId} from CRM calendar event notes.");
                                        break;
                                    } elseif (preg_match('/#(\d{5,})/i', $evTitle, $m)) {
                                        $wessApptId = $m[1];
                                        Log::info("Cancellation Auto-Recovery: Found WESS Booking ID {$wessApptId} from CRM calendar event title.");
                                        break;
                                    }
                                }
                            }
                        } catch (\Throwable $evEx) {
                            Log::warning("Could not auto-fetch calendar event for cancellation: " . $evEx->getMessage());
                        }
                    }

                    // Fallback 3: If phone is missing but contactId is present, fetch contact directly from GHL API
                    if (empty($phone) && !empty($contactId)) {
                        try {
                            $ghlCalendarService = app(GhlCalendarService::class);
                            $ghlContact = $ghlCalendarService->getContact($locationId, $contactId);
                            $phone = $ghlContact['phone'] ?? '';
                        } catch (\Throwable $contactEx) {
                            Log::warning("Could not fetch contact {$contactId} for cancellation: " . $contactEx->getMessage());
                        }
                    }

                    // Fallback 4: Check WESS candidate appointments around this time by customer phone
                    if (!$wessApptId && !empty($startTime) && !empty($phone)) {
                        try {
                            $parsedStart = Carbon::parse($startTime);
                            $candidateAppts = $wessClient->getAppointments($branchId, [
                                'date_from' => $parsedStart->copy()->subHours(2)->format('Y-m-d H:i:s'),
                                'date_to'   => $parsedStart->copy()->addHours(2)->format('Y-m-d H:i:s'),
                            ]);
                            $cleanPhone = WessClient::parsePhoneNumber($phone)['mobile'];
                            $bestMatch  = null;
                            $minDiff    = PHP_INT_MAX;

                            foreach ($candidateAppts as $cAppt) {
                                $cPhone = $cAppt['customer']['phone_mobile'] ?? ($cAppt['phone'] ?? '');
                                if (!empty($cleanPhone) && str_contains($cPhone, $cleanPhone)) {
                                    $cDate = !empty($cAppt['date']) ? Carbon::parse($cAppt['date']) : null;
                                    if ($cDate) {
                                        $diffMinutes = abs($cDate->diffInMinutes($parsedStart));
                                        if ($diffMinutes <= 120 && $diffMinutes < $minDiff) {
                                            $minDiff   = $diffMinutes;
                                            $bestMatch = $cAppt;
                                        }
                                    }
                                }
                            }

                            if ($bestMatch) {
                                $wessApptId = (string) $bestMatch['id'];
                                $customerId = $bestMatch['customer_id'] ?? ($bestMatch['customer']['id'] ?? null);
                                Log::info("Cancellation Auto-Recovery: Matched WESS appointment ID {$wessApptId} by phone & closest slot window (diff: {$minDiff} mins).");
                            }
                        } catch (\Throwable $searchEx) {
                            Log::warning("Could not auto-search candidate WESS appointments: " . $searchEx->getMessage());
                        }
                    }

                    // Resolve real customer ID for WESS cancellation
                    $customerId = $payload['customerId'] ?? ($payload['customer_id'] ?? null);
                    if (empty($customerId) && !empty($phone)) {
                        try {
                            $custLookup = $wessClient->lookupCustomerByPhone($phone);
                            if (!empty($custLookup['id'])) {
                                $customerId = (int) $custLookup['id'];
                            }
                        } catch (\Throwable $custEx) {
                            Log::warning("Could not lookup customer by phone for cancellation: " . $custEx->getMessage());
                        }
                    }

                    if (empty($customerId)) {
                        $customerId = 1; // Fallback
                    }

                    if ($wessApptId) {
                        $endpoint = "{$config->base_url}/branches/{$branchId}/appointments/{$wessApptId}/cancel";
                        try {
                            $res = $wessClient->cancelAppointment($branchId, $wessApptId, $customerId);
                        } catch (\Throwable $cancelEx) {
                            // If appointment is already cancelled in WESS, treat as success
                            if (stripos($cancelEx->getMessage(), 'cancel') !== false || stripos($cancelEx->getMessage(), 'already') !== false) {
                                Log::info("WESS appointment {$wessApptId} already cancelled: " . $cancelEx->getMessage());
                                $res = ['status' => 'already_cancelled', 'message' => $cancelEx->getMessage()];
                            } else {
                                throw $cancelEx;
                            }
                        }

                        if ($synced) {
                            $synced->update(['status' => 'cancelled']);
                        } else {
                            SyncedAppointment::updateOrCreate(
                                [
                                    'location_id'         => $locationId,
                                    'wess_appointment_id' => (string) $wessApptId,
                                ],
                                [
                                    'ghl_appointment_id' => (string) $appointmentId,
                                    'ghl_contact_id'     => $contactId ? (string) $contactId : null,
                                    'status'             => 'cancelled',
                                    'last_synced_at'     => now(),
                                ]
                            );
                        }

                        $log->update([
                            'endpoint'        => $endpoint,
                            'method'          => 'PATCH',
                            'response_status' => 200,
                            'response_body'   => json_encode($res),
                        ]);
                    } else {
                        Log::warning("Cancellation skipped for appointment {$appointmentId}: No corresponding WESS booking ID found.");
                        $log->update([
                            'response_status' => 200,
                            'response_body'   => json_encode([
                                'status'  => 'skipped',
                                'message' => "Appointment {$appointmentId} could not be linked to any WESS booking ID. Skipped cancellation in WESS.",
                            ]),
                        ]);
                    }
                }
                return;
            }

            // Booking / Creation / Update Event
            // Ensure default start time if not present
            $startTime = $startTime ?? now()->addDay()->format('Y-m-d 10:00:00');

            // If phone is missing but contactId is present, fetch contact directly from GHL API
            if (empty($phone) && !empty($contactId)) {
                try {
                    $ghlCalendarService = app(GhlCalendarService::class);
                    $ghlContact = $ghlCalendarService->getContact($locationId, $contactId);
                    if ($ghlContact) {
                        $phone     = $ghlContact['phone'] ?? $phone;
                        $firstName = $ghlContact['firstName'] ?? $firstName;
                        $lastName  = $ghlContact['lastName'] ?? $lastName;
                        $email     = $ghlContact['email'] ?? $email;
                    }
                } catch (Exception $e) {
                    Log::warning("Could not auto-fetch contact {$contactId} from GHL: " . $e->getMessage());
                }
            }

            // If phone is still missing, cannot book in WESS without customer phone number
            if (empty($phone)) {
                Log::warning("Appointment {$appointmentId} has no contact phone number. Skipping WESS booking push.");
                $log->update([
                    'response_status' => 200,
                    'response_body'   => json_encode([
                        'status'  => 'skipped',
                        'message' => 'Contact phone number is missing in CRM. WESS requires a customer phone number to book.',
                    ]),
                ]);
                return;
            }

            // 1. Lookup or Create Customer in WESS
            $isExistingCustomer = true;
            $customer = $wessClient->lookupCustomerByPhone($phone);

            if (!$customer) {
                $isExistingCustomer = false;
                $phoneData = WessClient::parsePhoneNumber($phone);
                $customer = $wessClient->createCustomer($branchId, [
                    'first_name'                => $firstName ?: 'Customer',
                    'last_name'                 => $lastName ?: '',
                    'phone_mobile_country_code' => $phoneData['country_code'],
                    'phone_mobile'              => $phoneData['mobile'],
                    'email'                     => $email ?: '',
                ]);
            }

            if (empty($customer['id'])) {
                Log::error("Failed to lookup or create WESS customer for phone {$phone}.");
                $log->update([
                    'response_status' => 200,
                    'response_body'   => json_encode([
                        'status'  => 'failed',
                        'message' => "Failed to resolve or create customer in WESS for phone {$phone}.",
                    ]),
                ]);
                return;
            }

            // Update GHL Contact custom fields (WESS Code, Last Visit, Salutation, Gender, NRIC, Contact Type, etc.)
            if (!empty($customer['id']) && !empty($contactId)) {
                try {
                    $lastVisitDate = !empty($startTime) ? date('Y-m-d', strtotime($startTime)) : null;
                    $wessCode      = $customer['code'] ?? (string)$customer['id'];
                    $salutation    = $customer['salutation'] ?? null;
                    $gender        = $customer['customer_detail']['gender'] ?? ($customer['gender'] ?? null);
                    $nric          = $customer['ic_no'] ?? null;
                    $contactType   = $isExistingCustomer ? 'Existing Member' : 'New Lead';
                    $prefName      = $firstName ?: ($customer['first_name'] ?? null);
                    $bdayMonth     = null;
                    if (!empty($contact['dateOfBirth'])) {
                        $bdayMonth = date('F', strtotime($contact['dateOfBirth']));
                    }

                    app(\App\Services\Ghl\GhlCustomFieldService::class)->updateContactCustomFields(
                        $locationId,
                        $contactId,
                        (string)$wessCode,
                        $lastVisitDate,
                        [
                            'salutation'     => $salutation,
                            'gender'         => $gender,
                            'nric'           => $nric,
                            'contact_type'   => $contactType,
                            'birthday_month' => $bdayMonth,
                            'preferred_name' => $prefName,
                        ]
                    );
                } catch (Exception $e) {
                    Log::warning("Could not sync custom fields for contact {$contactId}: " . $e->getMessage());
                }
            }

            // 2. Book appointment in WESS
            $wessAppointment = null;
            $services = $wessClient->getServices($branchId);

            // Prioritize S01007 or WESS Generic Service if configured
            $serviceId = null;
            foreach ($services as $s) {
                $sCode = $s['code'] ?? ($s['service']['code'] ?? '');
                $sName = $s['name'] ?? ($s['service']['name'] ?? '');
                if (strcasecmp($sCode, 'S01007') === 0 || stripos($sName, 'WESS Generic') !== false || stripos($sName, 'Generic') !== false) {
                    $serviceId = $s['id'] ?? ($s['product_id'] ?? ($s['service']['product_id'] ?? null));
                    break;
                }
            }

            if (!$serviceId && !empty($services)) {
                $serviceId = $services[0]['id'] ?? ($services[0]['product_id'] ?? ($services[0]['service']['product_id'] ?? null));
            }

            if (!$serviceId) {
                // Fallback to default or 1223 if sandbox
                $serviceId = 1223;
            }

            // Convert CRM start time to Branch local timezone (Asia/Singapore, UTC+8) for WESS booking
            $branchTz = 'Asia/Singapore';
            $apptDate = !empty($startTime) 
                ? Carbon::parse($startTime)->setTimezone($branchTz)->format('Y-m-d H:i:s') 
                : now($branchTz)->format('Y-m-d H:i:s');

            $bookingPayload = [
                'customer_id' => (int)$customer['id'],
                'date'        => $apptDate,
                'items'       => [
                    ['product_id' => $serviceId]
                ],
            ];

            // Dynamically resolve WESS employee ID from CRM assignedUserId / calendarId
            $assignedUserId = $appointment['assignedUserId'] ?? $appointment['userId'] ?? $payload['assignedUserId'] ?? $payload['userId'] ?? ($payload['appointment']['assignedUserId'] ?? null);
            $calendarId     = $appointment['calendarId'] ?? $payload['calendarId'] ?? ($payload['appointment']['calendarId'] ?? null);

            try {
                $ghlCalendarService = app(GhlCalendarService::class);
                $therapistMap       = $ghlCalendarService->getTherapistCalendarMap($locationId, $config->company_id);

                // If assignedUserId was provided but not found in cache, force fresh map
                if (!empty($assignedUserId) && !isset($therapistMap['by_user_id'][$assignedUserId])) {
                    $therapistMap = $ghlCalendarService->getTherapistCalendarMap($locationId, $config->company_id, true);
                }

                $targetUser = null;
                if (!empty($assignedUserId) && isset($therapistMap['by_user_id'][$assignedUserId])) {
                    $targetUser = $therapistMap['by_user_id'][$assignedUserId];
                } elseif (!empty($calendarId) && isset($therapistMap['by_cal_id'][$calendarId])) {
                    $targetUser = $therapistMap['by_cal_id'][$calendarId];
                }

                if ($targetUser) {
                    $wessEmployees = $wessClient->getEmployees($branchId);
                    $crmFirst    = strtolower(trim($targetUser['first_name'] ?? ''));
                    $crmLast     = strtoupper(trim($targetUser['last_name'] ?? ''));
                    $crmUserName = strtolower(trim($targetUser['user_name'] ?? ($targetUser['name'] ?? '')));
                    $crmFullNorm = strtolower(preg_replace('/[^a-z0-9]/', '', "{$crmFirst}{$crmLast}"));

                    foreach ($wessEmployees as $emp) {
                        $wFirst = strtolower(trim($emp['first_name'] ?? ''));
                        $wLast  = strtolower(trim($emp['last_name'] ?? ''));
                        $wFull  = strtolower(preg_replace('/[^a-z0-9]/', '', "{$wFirst}{$wLast}"));
                        $wCode  = strtoupper(trim($emp['code'] ?? ($emp['employee_code'] ?? '')));

                        if (
                            (!empty($crmLast) && !empty($wCode) && $crmLast === $wCode) ||
                            (!empty($crmFirst) && $wFirst === $crmFirst) ||
                            (!empty($wFirst) && str_starts_with($crmUserName, $wFirst)) ||
                            (!empty($wFirst) && str_starts_with($crmFirst, $wFirst)) ||
                            (!empty($wFull) && str_contains($crmFullNorm, $wFull))
                        ) {
                            $bookingPayload['employee_id'] = (int) $emp['id'];
                            $bookingPayload['employees']   = [(int) $emp['id']];
                            Log::info("Dynamically matched CRM user {$targetUser['user_name']} to WESS employee ID {$emp['id']} ({$emp['first_name']})");
                            break;
                        }
                    }
                }
            } catch (Exception $mapEx) {
                Log::warning("Could not auto-resolve therapist for WESS booking: " . $mapEx->getMessage());
            }

            // Layer 3: Change Detection for Existing Synced Appointments
            $existingSync = null;
            $oldWessId    = null;

            if ($appointmentId) {
                // Find latest active synced appointment for this CRM appointment
                $existingSync = SyncedAppointment::where('location_id', $locationId)
                    ->where('ghl_appointment_id', (string) $appointmentId)
                    ->where('status', '!=', 'cancelled')
                    ->latest('id')
                    ->first();

                // Fallback to any latest record if none currently active
                if (!$existingSync) {
                    $existingSync = SyncedAppointment::where('location_id', $locationId)
                        ->where('ghl_appointment_id', (string) $appointmentId)
                        ->latest('id')
                        ->first();
                }

                if ($existingSync && $existingSync->wess_appointment_id) {
                    $existingStartTime     = $existingSync->start_time ? Carbon::parse($existingSync->start_time)->format('Y-m-d H:i:s') : null;
                    $newStartTimeFormatted = Carbon::parse($apptDate)->format('Y-m-d H:i:s');

                    $hasTimeChanged     = ($existingStartTime !== $newStartTimeFormatted);
                    $hasUserChanged     = !empty($assignedUserId) && ($assignedUserId !== $existingSync->assigned_user_id);
                    $hasCalendarChanged = !empty($calendarId) && ($calendarId !== $existingSync->calendar_id);

                    // Rescheduling in WESS (cancelling old booking and creating new booking)
                    // is ONLY triggered if the appointment schedule (time, therapist, calendar) has actually changed.
                    // Generic status updates (e.g. 'confirmed', 'showed') must NOT cancel an active WESS booking.
                    $isRealUpdate = $hasTimeChanged || $hasUserChanged || $hasCalendarChanged;

                    if (!$isRealUpdate) {
                        // Update local status if changed in CRM, without cancelling the WESS booking
                        if (!empty($status) && strcasecmp($status, (string) $existingSync->status) !== 0) {
                            $existingSync->update(['status' => $status]);
                        }

                        Log::info("Loop prevention: Skipped appointment webhook {$appointmentId} as no schedule changes detected (already linked to WESS booking #{$existingSync->wess_appointment_id}).");
                        $log->update([
                            'response_status' => 200,
                            'response_body'   => json_encode([
                                'status'              => 'skipped',
                                'message'             => "Loop prevention: Appointment is already linked to WESS booking #{$existingSync->wess_appointment_id} with identical schedule details.",
                                'wess_appointment_id' => $existingSync->wess_appointment_id,
                            ]),
                        ]);
                        return;
                    }

                    $oldWessId = $existingSync->wess_appointment_id;
                    Log::info("AppointmentUpdate for {$appointmentId}: Rescheduling WESS booking (old: #{$oldWessId}, timeChanged=" . ($hasTimeChanged ? 'yes' : 'no') . ", userChanged=" . ($hasUserChanged ? 'yes' : 'no') . ")");

                    // Cancel ALL prior active bookings for this CRM appointment in WESS to prevent lingering active slots
                    $priorActiveSyncs = SyncedAppointment::where('location_id', $locationId)
                        ->where('ghl_appointment_id', (string) $appointmentId)
                        ->where('status', '!=', 'cancelled')
                        ->get();

                    if (!empty($customer['id'])) {
                        foreach ($priorActiveSyncs as $pSync) {
                            $targetWessId = $pSync->wess_appointment_id;
                            try {
                                $wessClient->cancelAppointment($branchId, $targetWessId, (int) $customer['id']);
                                Log::info("Cancelled prior WESS booking #{$targetWessId} for rescheduled appointment {$appointmentId}.");
                            } catch (\Throwable $cancelEx) {
                                Log::warning("Could not cancel old WESS booking #{$targetWessId} during update: " . $cancelEx->getMessage());
                            }
                            $pSync->update(['status' => 'cancelled']);
                        }
                    }
                }
            }

            $wessAppointment = $wessClient->bookAppointment($branchId, $bookingPayload);

            // Persist synced appointment record to prevent duplicate loops
            if (!empty($wessAppointment['id']) && $appointmentId) {
                $syncHash = md5($apptDate . ($wessAppointment['status'] ?? 'Request') . $serviceId . $customer['id']);

                // If this was an update, mark the prior WESS booking as cancelled
                if (!empty($oldWessId) && $oldWessId !== (string) $wessAppointment['id']) {
                    SyncedAppointment::where('location_id', $locationId)
                        ->where('wess_appointment_id', (string) $oldWessId)
                        ->update(['status' => 'cancelled']);
                }

                SyncedAppointment::updateOrCreate(
                    [
                        'location_id'         => $locationId,
                        'wess_appointment_id' => (string) $wessAppointment['id'],
                    ],
                    [
                        'ghl_appointment_id'  => (string) $appointmentId,
                        'ghl_contact_id'      => $contactId ? (string) $contactId : null,
                        'calendar_id'         => $calendarId ? (string) $calendarId : null,
                        'assigned_user_id'    => $assignedUserId ? (string) $assignedUserId : null,
                        'title'               => $title,
                        'status'              => (string) ($wessAppointment['status'] ?? 'Request'),
                        'start_time'          => $apptDate,
                        'end_time'            => !empty($appointment['endTime']) ? date('Y-m-d H:i:s', strtotime($appointment['endTime'])) : date('Y-m-d H:i:s', strtotime($apptDate . ' +1 hour')),
                        'sync_hash'           => $syncHash,
                        'last_synced_at'      => now(),
                    ]
                );
            }

            $endpoint = "{$config->base_url}/branches/{$branchId}/appointments";
            $log->update([
                'endpoint'        => $endpoint,
                'method'          => 'POST',
                'response_status' => 200,
                'response_body'   => json_encode([
                    'status'                  => !empty($oldWessId) ? 'updated' : 'synced',
                    'message'                 => !empty($oldWessId) ? "Appointment updated in WESS: rescheduled from booking #{$oldWessId} to #{$wessAppointment['id']}." : "Appointment booked in WESS #{$wessAppointment['id']}.",
                    'appointment_id'          => $appointmentId,
                    'old_wess_appointment_id' => $oldWessId,
                    'wess_appointment_id'     => $wessAppointment['id'] ?? null,
                    'wess_customer'           => $customer,
                    'wess_appointment'        => $wessAppointment,
                    'branch_id'               => $branchId,
                    'start_time'              => $apptDate,
                ]),
            ]);

        } catch (\Throwable $e) {
            Log::error("Failed to process appointment webhook for location {$locationId}: " . $e->getMessage());
            $log->update([
                'error_message'   => $e->getMessage(),
                'response_status' => 200, // Return 200 per GHL doc to avoid circuit breaker
                'response_body'   => json_encode([
                    'status'  => 'error',
                    'message' => $e->getMessage(),
                ]),
            ]);
        }
    }

    /**
     * Handle Contact Webhooks from GoHighLevel
     * Schema: { id, firstName, lastName, name, phone, email, customFields, tags }
     */
    protected function handleContactWebhook(?string $locationId, string $type, array $payload, WebhookLog $log): void
    {
        if (empty($locationId)) {
            return;
        }

        $config = WessConfig::where('location_id', $locationId)->first();
        if (!$config || empty($config->api_token) || !$config->is_sync_enabled) {
            return;
        }

        // Contact Delete
        if (str_contains($type, 'delete')) {
            $log->update([
                'response_status' => 200,
                'response_body'   => json_encode(['status' => 'acknowledged', 'message' => 'Contact deletion event received and logged.']),
            ]);
            return;
        }

        // Parse contact fields from flat or nested payload
        $contact   = $payload['contact'] ?? ($payload['data'] ?? $payload);
        $phone     = $contact['phone'] ?? ($payload['phone'] ?? '');
        $firstName = $contact['firstName'] ?? ($payload['firstName'] ?? '');
        $lastName  = $contact['lastName'] ?? ($payload['lastName'] ?? '');
        $email     = $contact['email'] ?? ($payload['email'] ?? '');
        $name      = $contact['name'] ?? ($payload['name'] ?? '');
        $ghlId     = $contact['id'] ?? ($payload['id'] ?? ($payload['contactId'] ?? null));

        // Echo Loop Prevention:
        // Ignore ContactUpdate webhooks that were recently triggered by our own custom fields update
        if ($ghlId && Cache::has("wess_origin_contact_{$ghlId}")) {
            Log::info("Loop prevention: Skipped contact webhook for contact {$ghlId} as it was recently updated by our system.");
            $log->update([
                'response_status' => 200,
                'response_body'   => json_encode([
                    'status'  => 'skipped',
                    'message' => 'Loop prevention: Contact update originated from internal sync. No action needed.',
                ]),
            ]);
            return;
        }

        if (empty($firstName) && !empty($name)) {
            $parts     = explode(' ', trim($name), 2);
            $firstName = $parts[0];
            $lastName  = $parts[1] ?? '';
        }

        if (empty($phone)) {
            $log->update([
                'response_status' => 200,
                'response_body'   => 'Skipped: No phone number provided in contact payload.',
            ]);
            return;
        }

        try {
            $wessClient = new WessClient($config->api_token, $config->base_url);
            $branchId   = $config->branch_id ?: 1;

            $isExistingCustomer = true;
            $customer = $wessClient->lookupCustomerByPhone($phone);
            $endpoint = "{$config->base_url}/customers/lookup/phone-number/" . preg_replace('/[^0-9]/', '', $phone);

            if (!$customer) {
                $isExistingCustomer = false;
                $endpoint = "{$config->base_url}/branches/{$branchId}/customers";
                $phoneData = WessClient::parsePhoneNumber($phone);
                $customer = $wessClient->createCustomer($branchId, [
                    'first_name'                => $firstName ?: 'Customer',
                    'last_name'                 => $lastName ?: '',
                    'phone_mobile_country_code' => $phoneData['country_code'],
                    'phone_mobile'              => $phoneData['mobile'],
                    'email'                     => $email ?: '',
                ]);
            }

            // If customer has WESS ID and we have GHL ID, update GHL custom fields
            if (!empty($customer['id']) && !empty($ghlId)) {
                try {
                    $wessCode    = $customer['code'] ?? (string)$customer['id'];
                    $salutation  = $customer['salutation'] ?? null;
                    $gender      = $customer['customer_detail']['gender'] ?? ($customer['gender'] ?? null);
                    $nric        = $customer['ic_no'] ?? null;
                    $contactType = $isExistingCustomer ? 'Existing Member' : 'New Lead';
                    $prefName    = $firstName ?: ($customer['first_name'] ?? null);
                    $bdayMonth   = null;
                    if (!empty($contact['dateOfBirth'])) {
                        $bdayMonth = date('F', strtotime($contact['dateOfBirth']));
                    }

                    $ghlCalendarService = app(GhlCalendarService::class);
                    $ghlCalendarService->updateContactWessFields(
                        $locationId,
                        $ghlId,
                        (string)$wessCode,
                        $customer['last_visit'] ?? null,
                        [
                            'salutation'     => $salutation,
                            'gender'         => $gender,
                            'nric'           => $nric,
                            'contact_type'   => $contactType,
                            'birthday_month' => $bdayMonth,
                            'preferred_name' => $prefName,
                        ]
                    );
                } catch (Exception $e) {
                    Log::warning("Could not update GHL custom fields for contact {$ghlId}: " . $e->getMessage());
                }
            }

            $log->update([
                'endpoint'        => $endpoint,
                'method'          => 'POST',
                'response_status' => 200,
                'response_body'   => json_encode(['wess_customer' => $customer]),
            ]);
        } catch (Exception $e) {
            Log::error("Failed to sync contact for location {$locationId}: " . $e->getMessage());
            $log->update([
                'error_message'   => $e->getMessage(),
                'response_status' => 200,
                'response_body'   => json_encode(['error' => $e->getMessage()]),
            ]);
        }
    }

    /**
     * Cleanup when an app is uninstalled or disconnected from a GHL location:
     * Deletes WESS configurations/credentials, GHL OAuth tokens, and all location logs.
     */
    protected function cleanupLocation(string $locationId): void
    {
        Log::info("Starting full uninstall cleanup for GHL location: {$locationId}");

        $cleanupNotes = [];

        // 1. Delete WESS config & credentials
        $wessDeleted = WessConfig::where('location_id', $locationId)->delete();
        $cleanupNotes[] = "Deleted {$wessDeleted} WESS configurations";

        // 2. Delete GHL OAuth Tokens for this location
        $tokenCount = GhlToken::where('location_id', $locationId)->delete();
        $cleanupNotes[] = "Deleted {$tokenCount} GHL tokens";

        // 3. Delete Webhook and Activity Logs for this location
        $logCount = WebhookLog::where('location_id', $locationId)->delete();
        $cleanupNotes[] = "Deleted {$logCount} webhook logs";

        // NOTE: We deliberately PRESERVE SyncedAppointment records here (Layer 1 Protection).
        // Preserving the mapping table ensures that if the user reinstalls/reconnects the app,
        // previous appointments are remembered and will NOT be duplicated on CRM calendars.

        Log::info("Completed cleanup for GHL location {$locationId}: " . implode('; ', $cleanupNotes));
    }

    /**
     * Cleanup when an app is uninstalled at Agency/Company level
     */
    protected function cleanupCompany(string $companyId): void
    {
        Log::info("Starting full uninstall cleanup for GHL company: {$companyId}");

        $tokens = GhlToken::where('company_id', $companyId)->get();
        foreach ($tokens as $token) {
            if (!empty($token->location_id)) {
                $this->cleanupLocation($token->location_id);
            }
        }

        GhlToken::where('company_id', $companyId)->delete();
        Log::info("Completed cleanup for GHL company: {$companyId}");
    }
}
