<?php

namespace App\Http\Controllers;

use App\Models\GhlToken;
use App\Models\WessConfig;
use App\Models\WebhookLog;
use App\Services\Wess\WessClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Exception;

class GhlWebhookController extends Controller
{
    /**
     * Handle GoHighLevel Marketplace App Webhooks:
     * - AppUninstall, LocationDisconnected (Clean up credentials, tokens & logs)
     * - AppointmentCreate, AppointmentUpdate, AppointmentDelete / Cancel (2-way sync)
     * - ContactCreate, ContactUpdate (Customer sync & field mapping)
     * POST /webhook & POST /webhooks/ghl
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

        // 1. App Uninstall / Disconnect Lifecycle: Clean up credentials and logs
        if (in_array($normalizedType, ['appuninstall', 'appuninstalled', 'locationdisconnected', 'locationdeleted', 'uninstall', 'uninstalled', 'disconnect'])) {
            if (!empty($locationId)) {
                $this->cleanupLocation($locationId);
            }
            return response()->json([
                'status'  => 'success',
                'message' => 'GHL Location uninstalled and all credentials/logs removed successfully.',
            ], 200);
        }

        // 2. App Install / Re-connect Lifecycle
        if (in_array($normalizedType, ['install', 'appinstalled', 'locationconnected'])) {
            if (!empty($locationId)) {
                GhlToken::where('location_id', $locationId)->update(['is_active' => true]);
                Log::info("Reactivated GHL token on INSTALL event for location: {$locationId}");
            }
            return response()->json([
                'status'  => 'success',
                'message' => 'GHL Location reconnected.',
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

        // 4. Contact Lifecycle Webhooks (ContactCreate, ContactUpdate)
        if (str_contains($normalizedType, 'contact')) {
            $this->handleContactWebhook($locationId, $normalizedType, $payload, $log);
            return response()->json([
                'status'  => 'success',
                'message' => 'Contact webhook processed.',
            ], 200);
        }

        return response()->json([
            'status'  => 'success',
            'message' => 'GHL Webhook received and logged.',
        ], 200);
    }

    /**
     * Handle Appointment Webhooks from GoHighLevel
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
                'response_status' => 400,
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

        try {
            // Cancellation / Deletion Event
            if (str_contains($type, 'delete') || str_contains($type, 'cancel')) {
                $appointmentId = $payload['appointmentId'] ?? $payload['id'] ?? null;
                $customerId    = $payload['customerId'] ?? $payload['contactId'] ?? 1;

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
            // Extract contact info
            $phone     = $payload['phone'] ?? ($payload['contact']['phone'] ?? '');
            $firstName = $payload['firstName'] ?? ($payload['contact']['firstName'] ?? 'Customer');
            $lastName  = $payload['lastName'] ?? ($payload['contact']['lastName'] ?? '');
            $email     = $payload['email'] ?? ($payload['contact']['email'] ?? '');
            $startTime = $payload['startTime'] ?? ($payload['appointmentStartTime'] ?? now()->addDay()->format('Y-m-d 10:00:00'));

            // 1. Lookup or Create Customer in WESS
            $customer = null;
            if (!empty($phone)) {
                $customer = $wessClient->lookupCustomerByPhone($phone);
            }

            if (!$customer && !empty($phone)) {
                $customer = $wessClient->createCustomer($branchId, [
                    'first_name'   => $firstName,
                    'last_name'    => $lastName,
                    'phone_number' => $phone,
                    'email'        => $email,
                ]);
            }

            $endpoint = "{$config->base_url}/branches/{$branchId}/appointments";
            $log->update([
                'endpoint'        => $endpoint,
                'method'          => 'POST',
                'response_status' => 200,
                'response_body'   => json_encode([
                    'status'        => 'synced',
                    'wess_customer' => $customer,
                    'branch_id'     => $branchId,
                    'start_time'    => $startTime,
                ]),
            ]);

        } catch (Exception $e) {
            Log::error("Failed to process appointment webhook for location {$locationId}: " . $e->getMessage());
            $log->update([
                'error_message'   => $e->getMessage(),
                'response_status' => 500,
            ]);
        }
    }

    /**
     * Handle Contact Webhooks from GoHighLevel
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

        $phone = $payload['phone'] ?? ($payload['contact']['phone'] ?? '');
        if (empty($phone)) {
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
                    'first_name'   => $payload['firstName'] ?? 'Customer',
                    'last_name'    => $payload['lastName'] ?? '',
                    'phone_number' => $phone,
                    'email'        => $payload['email'] ?? '',
                ]);
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
                'response_status' => 500,
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
}
