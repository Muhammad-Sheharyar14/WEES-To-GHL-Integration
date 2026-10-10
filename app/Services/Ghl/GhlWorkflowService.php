<?php

namespace App\Services\Ghl;

use App\Models\GhlToken;
use App\Models\GhlWorkflowTrigger;
use App\Models\WebhookLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class GhlWorkflowService
{
    protected GhlAuthService $authService;

    public function __construct(GhlAuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Handle GHL Custom Workflow Trigger Subscription request
     * (Called when user adds or configures a trigger in a GHL Workflow)
     */
    public function handleSubscription(array $payload, array $headers = [], ?string $triggerType = null): GhlWorkflowTrigger
    {
        // 1. Extract location, company, and workflow info from GHL payload structure
        $triggerData = $payload['triggerData'] ?? [];
        $extras      = $payload['extras'] ?? [];

        $locationId = $extras['locationId'] 
            ?? $payload['locationId'] 
            ?? $payload['location_id'] 
            ?? ($headers['locationid'][0] ?? null);

        $companyId = $extras['companyId'] 
            ?? $payload['companyId'] 
            ?? $payload['company_id'] 
            ?? null;

        $workflowId = $extras['workflowId'] 
            ?? $payload['workflowId'] 
            ?? $payload['workflow_id'] 
            ?? null;

        $triggerId = $triggerData['id'] 
            ?? $payload['triggerId'] 
            ?? $payload['trigger_id'] 
            ?? null;

        $targetUrl = $triggerData['targetUrl'] 
            ?? $payload['targetUrl'] 
            ?? $payload['target_url'] 
            ?? ($payload['url'] ?? null);

        $eventType = strtoupper($triggerData['eventType'] ?? ($payload['eventType'] ?? 'CREATED'));

        // Determine trigger type if not explicitly supplied
        if (empty($triggerType)) {
            $key = strtolower($triggerData['key'] ?? ($payload['meta']['key'] ?? ''));
            if (str_contains($key, 'order')) {
                $triggerType = 'order';
            } else {
                $triggerType = 'customer_created';
            }
        }

        // 2. Log incoming subscription payload for inspection
        $log = WebhookLog::create([
            'source'          => 'ghl_subscription',
            'location_id'     => $locationId,
            'event_type'      => 'trigger_' . strtolower($eventType),
            'headers'         => $headers,
            'payload'         => $payload,
            'response_status' => 200,
            'response_body'   => 'Processing trigger subscription',
        ]);

        // 3. Validation: Verify location ID and targetUrl presence
        if (empty($locationId) || empty($targetUrl)) {
            $errorMsg = 'Missing locationId or targetUrl in GHL workflow trigger payload.';
            $log->update(['error_message' => $errorMsg, 'response_status' => 422]);
            throw new Exception($errorMsg);
        }

        // 4. Security Check: Verify that the app is authorized and installed for this location
        $token = GhlToken::where(function ($q) use ($locationId, $companyId) {
            $q->where('location_id', $locationId);
            if (!empty($companyId)) {
                $q->orWhere('company_id', $companyId);
            }
        })->first();

        if (!$token) {
            $errorMsg = "Unauthorized: GoHighLevel App is not installed for location: {$locationId}.";
            Log::warning('Unauthorized GHL trigger subscription attempt', [
                'location_id' => $locationId,
                'target_url'  => $targetUrl,
                'workflow_id' => $workflowId,
            ]);
            $log->update(['error_message' => $errorMsg, 'response_status' => 403]);
            throw new Exception($errorMsg);
        }

        if (!$token->is_active) {
            $token->update(['is_active' => true]);
        }

        // 5. Handle DELETED event if GHL notifies trigger removal
        if (in_array($eventType, ['DELETED', 'UNSUBSCRIBED'])) {
            $query = GhlWorkflowTrigger::where('location_id', $locationId);
            if ($triggerId) {
                $query->where('trigger_id', $triggerId);
            } elseif ($targetUrl) {
                $query->where('target_url', $targetUrl);
            }
            $deletedCount = $query->delete();

            Log::info('GHL Workflow Trigger Deleted from DB', [
                'location_id'   => $locationId,
                'trigger_id'    => $triggerId,
                'target_url'    => $targetUrl,
                'deleted_count' => $deletedCount,
            ]);

            $log->update(['response_body' => "Trigger deleted successfully ({$deletedCount} deleted)"]);
            
            return new GhlWorkflowTrigger([
                'location_id' => $locationId,
                'is_active'   => false,
            ]);
        }

        // 6. Store or update active trigger subscription (by trigger_id or target_url)
        $lookup = ['location_id' => $locationId];
        if (!empty($triggerId)) {
            $lookup['trigger_id'] = $triggerId;
        } else {
            $lookup['target_url'] = $targetUrl;
        }

        $trigger = GhlWorkflowTrigger::updateOrCreate(
            $lookup,
            [
                'company_id'               => $companyId,
                'workflow_id'              => $workflowId,
                'trigger_id'               => $triggerId,
                'trigger_type'             => $triggerType,
                'event_type'               => $eventType,
                'target_url'               => $targetUrl,
                'raw_subscription_payload' => $payload,
                'is_active'                => true,
            ]
        );

        Log::info('GHL Workflow Trigger Subscribed Successfully', [
            'id'          => $trigger->id,
            'location_id' => $locationId,
            'workflow_id' => $workflowId,
            'trigger_id'  => $triggerId,
            'target_url'  => $targetUrl,
            'event_type'  => $eventType,
        ]);

        $log->update(['response_body' => "Trigger {$eventType} saved and mapped to location"]);

        return $trigger;
    }

    /**
     * Dispatch customer created event to all active GHL workflow trigger target URLs for this location
     */
    public function dispatchCustomerCreated(string $locationId, array $customerData): array
    {
        $triggers = GhlWorkflowTrigger::where('location_id', $locationId)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('trigger_type', 'customer_created')
                  ->orWhere('trigger_type', 'customer')
                  ->orWhereNull('trigger_type');
            })
            ->get();

        $results = [];

        // Extract customer email for searchable indexing in webhook logs
        $customerEmail = $customerData['contact']['email']
            ?? ($customerData['customer']['email']
            ?? ($customerData['email'] ?? null));
        if ($customerEmail) {
            $customerEmail = strtolower(trim((string)$customerEmail));
        }

        if ($triggers->isEmpty()) {
            Log::info("No active GHL workflow triggers found for location: {$locationId}");
            WebhookLog::create([
                'source'          => 'ghl_workflow_dispatch',
                'location_id'     => $locationId,
                'email'           => $customerEmail,
                'event_type'      => 'dispatch_skipped',
                'payload'         => $customerData,
                'response_status' => 200,
                'response_body'   => 'No active triggers subscribed for this location',
            ]);
            return $results;
        }

        // 1. Retrieve valid access token for this location
        $accessToken = null;
        try {
            $accessToken = $this->authService->getValidAccessToken($locationId);
        } catch (\Throwable $e) {
            Log::warning("Could not retrieve GHL access token for location {$locationId} during workflow dispatch: " . $e->getMessage());
        }

        // 2. Prepare headers required by GHL Workflow Marketplace trigger execute endpoints
        $headers = [
            'Version'      => config('ghl.api_version', 'v3'),
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if (!empty($accessToken)) {
            $headers['Authorization'] = 'Bearer ' . $accessToken;
        }

        foreach ($triggers as $trigger) {
            // 3. Evaluate any user-configured filters on this trigger (e.g. action == "created" or action == "updated")
            if (!$this->matchesFilters($trigger, $customerData)) {
                Log::info('GHL workflow trigger skipped: filters do not match customer payload', [
                    'trigger_id'  => $trigger->trigger_id ?? $trigger->id,
                    'workflow_id' => $trigger->workflow_id,
                    'target_url'  => $trigger->target_url,
                    'action'      => $customerData['action'] ?? null,
                ]);

                WebhookLog::create([
                    'source'          => 'ghl_workflow_dispatch',
                    'location_id'     => $locationId,
                    'email'           => $customerEmail,
                    'event_type'      => 'dispatch_skipped_filter_mismatch',
                    'payload'         => [
                        'trigger_id'  => $trigger->trigger_id,
                        'workflow_id' => $trigger->workflow_id,
                        'target_url'  => $trigger->target_url,
                        'filters'     => $trigger->raw_subscription_payload['triggerData']['filters'] ?? [],
                        'action'      => $customerData['action'] ?? null,
                    ],
                    'response_status' => 200,
                    'response_body'   => 'Skipped dispatch: trigger filters do not match customer payload',
                ]);
                continue;
            }

            try {
                $response = Http::withHeaders($headers)
                    ->timeout(15)
                    ->post($trigger->target_url, $customerData);

                $log = WebhookLog::create([
                    'source'          => 'ghl_workflow_dispatch',
                    'location_id'     => $locationId,
                    'email'           => $customerEmail,
                    'event_type'      => 'customer_created_dispatched',
                    'payload'         => [
                        'target_url'   => $trigger->target_url,
                        'workflow_id'  => $trigger->workflow_id,
                        'trigger_id'   => $trigger->trigger_id,
                        'data'         => $customerData,
                    ],
                    'response_status' => $response->status(),
                    'response_body'   => substr($response->body(), 0, 1000),
                ]);

                $results[] = [
                    'trigger_id' => $trigger->id,
                    'target_url' => $trigger->target_url,
                    'status'     => $response->status(),
                    'successful' => $response->successful(),
                ];
            } catch (Exception $e) {
                Log::error('Failed to dispatch payload to GHL workflow target URL', [
                    'target_url' => $trigger->target_url,
                    'error'      => $e->getMessage(),
                ]);

                WebhookLog::create([
                    'source'          => 'ghl_workflow_dispatch',
                    'location_id'     => $locationId,
                    'email'           => $customerEmail,
                    'event_type'      => 'dispatch_failed',
                    'payload'         => [
                        'target_url'  => $trigger->target_url,
                        'data'        => $customerData,
                    ],
                    'error_message'   => $e->getMessage(),
                ]);

                $results[] = [
                    'trigger_id' => $trigger->id,
                    'target_url' => $trigger->target_url,
                    'error'      => $e->getMessage(),
                    'successful' => false,
                ];
            }
        }

        return $results;
    }

    /**
     * Dispatch order event to all active GHL workflow trigger target URLs for this location
     */
    public function dispatchOrderEvent(string $locationId, array $orderData): array
    {
        $triggers = GhlWorkflowTrigger::where('location_id', $locationId)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('trigger_type', 'order')
                  ->orWhere('trigger_type', 'order_created');
            })
            ->get();

        $results = [];

        // Extract order/customer email for searchable indexing in webhook logs
        $orderEmail = $orderData['contact']['email']
            ?? ($orderData['order']['email']
            ?? ($orderData['order']['customer']['email']
            ?? ($orderData['billingAddress']['email']
            ?? ($orderData['customer']['email']
            ?? ($orderData['email'] ?? null)))));
        if ($orderEmail) {
            $orderEmail = strtolower(trim((string)$orderEmail));
        }

        if ($triggers->isEmpty()) {
            Log::info("No active GHL order workflow triggers found for location: {$locationId}");
            WebhookLog::create([
                'source'          => 'ghl_workflow_dispatch',
                'location_id'     => $locationId,
                'email'           => $orderEmail,
                'event_type'      => 'dispatch_skipped_order',
                'payload'         => $orderData,
                'response_status' => 200,
                'response_body'   => 'No active order triggers subscribed for this location',
            ]);
            return $results;
        }

        // 1. Retrieve valid access token for this location
        $accessToken = null;
        try {
            $accessToken = $this->authService->getValidAccessToken($locationId);
        } catch (\Throwable $e) {
            Log::warning("Could not retrieve GHL access token for location {$locationId} during workflow dispatch: " . $e->getMessage());
        }

        // 2. Prepare headers required by GHL Workflow Marketplace trigger execute endpoints
        $headers = [
            'Version'      => config('ghl.api_version', 'v3'),
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if (!empty($accessToken)) {
            $headers['Authorization'] = 'Bearer ' . $accessToken;
        }

        foreach ($triggers as $trigger) {
            // 3. Evaluate any user-configured filters (e.g. action == "paid" or action == "created" or order.status == "completed")
            if (!$this->matchesFilters($trigger, $orderData)) {
                Log::info('GHL workflow trigger skipped: filters do not match order payload', [
                    'trigger_id'  => $trigger->trigger_id ?? $trigger->id,
                    'workflow_id' => $trigger->workflow_id,
                    'target_url'  => $trigger->target_url,
                    'action'      => $orderData['action'] ?? null,
                ]);

                WebhookLog::create([
                    'source'          => 'ghl_workflow_dispatch',
                    'location_id'     => $locationId,
                    'email'           => $orderEmail,
                    'event_type'      => 'dispatch_skipped_filter_mismatch',
                    'payload'         => [
                        'trigger_id'  => $trigger->trigger_id,
                        'workflow_id' => $trigger->workflow_id,
                        'target_url'  => $trigger->target_url,
                        'filters'     => $trigger->raw_subscription_payload['triggerData']['filters'] ?? [],
                        'action'      => $orderData['action'] ?? null,
                    ],
                    'response_status' => 200,
                    'response_body'   => 'Skipped dispatch: trigger filters do not match order payload',
                ]);
                continue;
            }

            try {
                $response = Http::withHeaders($headers)
                    ->timeout(15)
                    ->post($trigger->target_url, $orderData);

                $log = WebhookLog::create([
                    'source'          => 'ghl_workflow_dispatch',
                    'location_id'     => $locationId,
                    'email'           => $orderEmail,
                    'event_type'      => 'order_event_dispatched',
                    'payload'         => [
                        'target_url'   => $trigger->target_url,
                        'workflow_id'  => $trigger->workflow_id,
                        'trigger_id'   => $trigger->trigger_id,
                        'data'         => $orderData,
                    ],
                    'response_status' => $response->status(),
                    'response_body'   => substr($response->body(), 0, 1000),
                ]);

                $results[] = [
                    'trigger_id' => $trigger->id,
                    'target_url' => $trigger->target_url,
                    'status'     => $response->status(),
                    'successful' => $response->successful(),
                ];
            } catch (Exception $e) {
                Log::error('Failed to dispatch order payload to GHL workflow target URL', [
                    'target_url' => $trigger->target_url,
                    'error'      => $e->getMessage(),
                ]);

                WebhookLog::create([
                    'source'          => 'ghl_workflow_dispatch',
                    'location_id'     => $locationId,
                    'email'           => $orderEmail,
                    'event_type'      => 'dispatch_failed',
                    'payload'         => [
                        'target_url'  => $trigger->target_url,
                        'data'        => $orderData,
                    ],
                    'error_message'   => $e->getMessage(),
                ]);

                $results[] = [
                    'trigger_id' => $trigger->id,
                    'target_url' => $trigger->target_url,
                    'error'      => $e->getMessage(),
                    'successful' => false,
                ];
            }
        }

        return $results;
    }

    /**
     * Check whether payload satisfies all filters configured on a GHL workflow trigger
     */
    public function matchesFilters(GhlWorkflowTrigger $trigger, array $data): bool
    {
        $payload = $trigger->raw_subscription_payload ?? [];
        $filters = $payload['triggerData']['filters'] 
            ?? $payload['filters'] 
            ?? [];

        if (empty($filters) || !is_array($filters)) {
            return true; // No filters means this trigger accepts all events
        }

        foreach ($filters as $filter) {
            if (!is_array($filter)) {
                continue;
            }

            $field = $filter['field'] ?? $filter['id'] ?? null;
            if (!$field) {
                continue;
            }

            $operator = $filter['operator'] ?? '==';
            $expected = $filter['value'] ?? null;

            // Resolve actual value from data using dot-notation (e.g. "action", "order.paymentStatus", "customer.email")
            $actual = data_get($data, $field);

            // Fallback for fields provided without prefix
            if ($actual === null && !str_contains($field, '.')) {
                $actual = data_get($data, "customer.{$field}") 
                    ?? data_get($data, "order.{$field}") 
                    ?? data_get($data, "contact.{$field}");
            }

            if (!$this->evaluateFilterCondition($actual, $operator, $expected)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Evaluate single condition against actual customer data
     */
    protected function evaluateFilterCondition($actual, string $operator, $expected): bool
    {
        // Boolean comparison
        if (is_bool($expected) || (is_string($expected) && in_array(strtolower($expected), ['true', 'false'], true))) {
            $expectedBool = filter_var($expected, FILTER_VALIDATE_BOOLEAN);
            $actualBool = filter_var($actual, FILTER_VALIDATE_BOOLEAN);

            return in_array($operator, ['!=', '<>', 'neq'], true) 
                ? ($actualBool !== $expectedBool) 
                : ($actualBool === $expectedBool);
        }

        // Case-insensitive string normalization
        $normActual = is_string($actual) ? strtolower(trim($actual)) : $actual;
        $normExpected = is_string($expected) ? strtolower(trim($expected)) : $expected;

        switch (strtolower($operator)) {
            case 'string-contains-any-of':
            case 'string-contains':
            case 'contains':
                if (is_array($expected)) {
                    foreach ($expected as $phrase) {
                        if ($phrase !== '' && str_contains(strtolower((string)$actual), strtolower(trim((string)$phrase)))) {
                            return true;
                        }
                    }
                    return false;
                }
                if (is_string($actual) && is_string($expected)) {
                    return str_contains(strtolower($actual), strtolower($expected));
                }
                return false;

            case 'string-not-contains-any-of':
            case 'string-does-not-contain':
            case 'not_contains':
            case 'not contains':
            case 'does_not_contain':
                if (is_array($expected)) {
                    foreach ($expected as $phrase) {
                        if ($phrase !== '' && str_contains(strtolower((string)$actual), strtolower(trim((string)$phrase)))) {
                            return false;
                        }
                    }
                    return true;
                }
                if (is_string($actual) && is_string($expected)) {
                    return !str_contains(strtolower($actual), strtolower($expected));
                }
                return true;

            case 'string-is-any-of':
            case 'string-is':
            case 'in':
                if (is_array($expected)) {
                    $expectedArray = array_map(fn($v) => strtolower(trim((string)$v)), $expected);
                    return in_array((string)$normActual, $expectedArray, true);
                }
                return $normActual == $normExpected;

            case 'string-is-not-any-of':
            case 'string-is-not':
            case 'not_in':
                if (is_array($expected)) {
                    $expectedArray = array_map(fn($v) => strtolower(trim((string)$v)), $expected);
                    return !in_array((string)$normActual, $expectedArray, true);
                }
                return $normActual != $normExpected;

            case '==':
            case 'eq':
            case '=':
                if (is_array($expected)) {
                    $expectedArray = array_map(fn($v) => strtolower(trim((string)$v)), $expected);
                    return in_array((string)$normActual, $expectedArray, true);
                }
                return $normActual == $normExpected;

            case '!=':
            case '<>':
            case 'neq':
                if (is_array($expected)) {
                    $expectedArray = array_map(fn($v) => strtolower(trim((string)$v)), $expected);
                    return !in_array((string)$normActual, $expectedArray, true);
                }
                return $normActual != $normExpected;

            case 'string-is-empty':
            case 'empty':
            case 'is_empty':
                return empty($actual);

            case 'string-is-not-empty':
            case 'not_empty':
            case 'is_not_empty':
                return !empty($actual);

            case '>':
                return $actual > $expected;

            case '<':
                return $actual < $expected;

            case '>=':
                return $actual >= $expected;

            case '<=':
                return $actual <= $expected;

            default:
                if (is_array($expected)) {
                    $expectedArray = array_map(fn($v) => strtolower(trim((string)$v)), $expected);
                    return in_array((string)$normActual, $expectedArray, true);
                }
                return $normActual == $normExpected;
        }
    }
}
