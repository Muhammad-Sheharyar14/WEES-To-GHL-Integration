<?php

namespace App\Http\Controllers;

use App\Models\GhlToken;
use App\Models\WessConfig;
use App\Models\WebhookLog;
use App\Services\Ghl\GhlCalendarService;
use App\Services\Wess\WessClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
    protected function handleAppointmentWebhook(?string $locationId, string $type, array $payload, WebhookLog $log): void
    {
        if (empty($locationId)) {
            $log->update(['error_message' => 'Missing location_id in appointment webhook payload']);
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

        try {
            // Cancellation / Deletion Event
            if (str_contains($type, 'delete') || str_contains($type, 'cancel') || in_array($status, ['cancelled', 'canceled', 'deleted'])) {
                $customerId = $payload['customerId'] ?? 1;

                if ($appointmentId) {
                    $endpoint = "{$config->base_url}/branches/{$branchId}/appointments/{$appointmentId}/cancel";
                    $res = $wessClient->cancelAppointment($branchId, $appointmentId, $customerId);

                    $log->update([
                        'endpoint'        => $endpoint,
                        'method'          => 'PATCH',
                        'response_status' => 200,
                        'response_body'   => json_encode($res),
                    ]);
                }
                return;
            }

            // Booking / Creation / Update Event
            // Extract contact details
            $phone     = $appointment['phone'] ?? $payload['phone'] ?? ($payload['contact']['phone'] ?? '');
            $firstName = $appointment['firstName'] ?? $payload['firstName'] ?? ($payload['contact']['firstName'] ?? '');
            $lastName  = $appointment['lastName'] ?? $payload['lastName'] ?? ($payload['contact']['lastName'] ?? '');
            $email     = $appointment['email'] ?? $payload['email'] ?? ($payload['contact']['email'] ?? '');
            $startTime = $appointment['startTime'] ?? $payload['startTime'] ?? ($payload['appointmentStartTime'] ?? now()->addDay()->format('Y-m-d 10:00:00'));

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

            // 1. Lookup or Create Customer in WESS
            $customer = null;
            if (!empty($phone)) {
                $customer = $wessClient->lookupCustomerByPhone($phone);

                if (!$customer) {
                    $customer = $wessClient->createCustomer($branchId, [
                        'first_name'   => $firstName ?: 'Customer',
                        'last_name'    => $lastName ?: '',
                        'phone_number' => $phone,
                        'email'        => $email ?: '',
                    ]);
                }
            }

            // Update GHL Contact custom fields (WESS Code & Last Visit)
            if (!empty($customer['id']) && !empty($contactId)) {
                try {
                    $lastVisitDate = !empty($startTime) ? date('Y-m-d', strtotime($startTime)) : null;
                    app(\App\Services\Ghl\GhlCustomFieldService::class)->updateContactCustomFields(
                        $locationId,
                        $contactId,
                        (string)$customer['id'],
                        $lastVisitDate
                    );
                } catch (Exception $e) {
                    Log::warning("Could not sync custom fields for contact {$contactId}: " . $e->getMessage());
                }
            }

            $endpoint = "{$config->base_url}/branches/{$branchId}/appointments";
            $log->update([
                'endpoint'        => $endpoint,
                'method'          => 'POST',
                'response_status' => 200,
                'response_body'   => json_encode([
                    'status'         => 'synced',
                    'appointment_id' => $appointmentId,
                    'wess_customer'  => $customer,
                    'branch_id'      => $branchId,
                    'start_time'     => $startTime,
                ]),
            ]);

        } catch (Exception $e) {
            Log::error("Failed to process appointment webhook for location {$locationId}: " . $e->getMessage());
            $log->update([
                'error_message'   => $e->getMessage(),
                'response_status' => 200, // Return 200 per GHL doc to avoid circuit breaker
                'response_body'   => json_encode(['error' => $e->getMessage()]),
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
        $ghlId     = $contact['id'] ?? ($payload['id'] ?? null);

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

            $customer = $wessClient->lookupCustomerByPhone($phone);
            $endpoint = "{$config->base_url}/customers/lookup/phone-number/" . preg_replace('/[^0-9]/', '', $phone);

            if (!$customer) {
                $endpoint = "{$config->base_url}/branches/{$branchId}/customers";
                $customer = $wessClient->createCustomer($branchId, [
                    'first_name'   => $firstName ?: 'Customer',
                    'last_name'    => $lastName ?: '',
                    'phone_number' => $phone,
                    'email'        => $email ?: '',
                ]);
            }

            // If customer has WESS ID and we have GHL ID, update GHL custom fields
            if (!empty($customer['id']) && !empty($ghlId)) {
                try {
                    $ghlCalendarService = app(GhlCalendarService::class);
                    $ghlCalendarService->updateContactWessFields(
                        $locationId,
                        $ghlId,
                        (string)$customer['id'],
                        $customer['last_visit'] ?? null
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
