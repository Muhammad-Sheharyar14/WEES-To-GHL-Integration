<?php

namespace App\Http\Controllers;

use App\Models\GhlToken;
use App\Models\WessConfig;
use App\Models\WebhookLog;
use App\Services\Ghl\GhlAuthService;
use App\Services\Wess\WessClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Exception;

class CustomPageController extends Controller
{
    protected GhlAuthService $ghlAuthService;

    public function __construct(GhlAuthService $ghlAuthService)
    {
        $this->ghlAuthService = $ghlAuthService;
    }

    /**
     * Render the GHL Embedded Custom Settings Page
     */
    public function index(Request $request)
    {
        $sharedSecretKey = config('ghl.shared_secret', '');

        return view('custom_page', compact('sharedSecretKey'));
    }

    /**
     * Initialize / fetch account status, CRM tokens, and WESS credentials.
     * 1. Check if account already has a valid token (auto-refresh if expired).
     * 2. If an Agency token exists, resolve an Account token.
     * 3. Return WESS configuration and master toggle switch state.
     */
    public function getCredentials(Request $request)
    {
        $locationId = $request->input('locationId') ?: $request->input('location_id');
        $companyId  = $request->input('companyId') ?: $request->input('company_id');

        if (empty($locationId)) {
            return response()->json([
                'success' => false,
                'message' => 'Account ID is required. Please ensure this page is opened inside your CRM account.'
            ], 422);
        }

        // 1. Resolve CRM Account Token
        $ghlToken = null;
        $ghlStatus = 'disconnected';
        $tokenError = null;

        try {
            $ghlToken = $this->ghlAuthService->ensureLocationToken($locationId, $companyId);
            if ($ghlToken) {
                $ghlStatus = 'connected';
            }
        } catch (Exception $e) {
            $tokenError = $e->getMessage();
            Log::warning("Token resolution warning for account {$locationId}: " . $e->getMessage());
        }

        // 2. Fetch CRM Calendars
        $calendars = [];
        try {
            $calendarService = app(\App\Services\Ghl\GhlCalendarService::class);
            $calendars = $calendarService->getCalendars($locationId);
        } catch (Exception $e) {
            Log::info("Could not fetch CRM calendars during custom page init: " . $e->getMessage());
        }

        // 3. Fetch WESS Configuration (No fallback credentials)
        $config = WessConfig::where('location_id', $locationId)->first();

        $branches = [];
        if ($config && !empty($config->api_token) && !empty($config->base_url)) {
            try {
                $client = new WessClient($config->api_token, $config->base_url);
                $branches = $client->getBranches();
            } catch (Exception $e) {
                // If branch retrieval fails, log gracefully
                Log::info("Could not fetch branches during custom page init: " . $e->getMessage());
            }
        }

        return response()->json([
            'success'         => true,
            'location_id'     => $locationId,
            'company_id'      => $companyId,
            'ghl_connected'   => (bool)$ghlToken,
            'crm_connected'   => (bool)$ghlToken,
            'ghl_status'      => $ghlStatus,
            'crm_status'      => $ghlStatus,
            'token_error'     => $tokenError,
            'is_verified'     => $config ? (bool)$config->is_connected : false,
            'is_sync_enabled' => $config ? (bool)$config->is_sync_enabled : true,
            'credentials'     => [
                'api_token'        => $config ? $config->api_token : '',
                'base_url'         => $config ? $config->base_url : '',
                'branch_id'        => $config ? $config->branch_id : '',
                'branch_name'      => $config ? $config->branch_name : '',
                'calendar_id'      => $config ? $config->calendar_id : '',
                'calendar_name'    => $config ? $config->calendar_name : '',
                'calendar_user_id' => $config ? $config->calendar_user_id : '',
                'is_sync_enabled'  => $config ? (bool)$config->is_sync_enabled : true,
                'is_connected'     => $config ? (bool)$config->is_connected : false,
                'last_synced_at'   => $config && $config->last_synced_at ? $config->last_synced_at->toIso8601String() : null,
            ],
            'branches'        => $branches,
            'calendars'       => $calendars,
        ]);
    }

    /**
     * Test WESS Credentials live against WESS sandbox / production API
     */
    public function testConnection(Request $request)
    {
        $validated = $request->validate([
            'api_token' => 'required|string',
            'base_url'  => 'required|url',
        ]);

        try {
            $client = new WessClient($validated['api_token'], $validated['base_url']);

            // 1. Verify user & permissions
            $authData = $client->testConnection();

            // 2. Fetch available branches
            $branches = $client->getBranches();

            return response()->json([
                'success'     => true,
                'message'     => 'WESS API credentials verified successfully!',
                'user'        => $authData['user'],
                'permissions' => $authData['permissions'],
                'branches'    => $branches,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'WESS Connection failed: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Save WESS Credentials & Configuration for a location
     */
    public function saveCredentials(Request $request)
    {
        $locationId = $request->input('locationId') ?: $request->input('location_id');
        $companyId  = $request->input('companyId') ?: $request->input('company_id');
        $apiToken   = trim($request->input('api_token') ?? '');
        $baseUrl    = trim($request->input('base_url') ?? config('wess.default_base_url'));
        $branchId      = $request->input('branch_id');
        $branchName    = trim($request->input('branch_name') ?? '');
        $calendarId    = $request->input('calendar_id');
        $calendarName  = trim($request->input('calendar_name') ?? '');
        $calendarUserId = trim($request->input('calendar_user_id') ?? '');
        $isSyncEnabled = $request->boolean('is_sync_enabled', true);

        if (empty($locationId)) {
            return response()->json([
                'success' => false,
                'message' => 'Location ID is required. Please ensure the app is opened inside GoHighLevel.',
            ], 422);
        }

        if (empty($apiToken)) {
            return response()->json([
                'success' => false,
                'message' => 'WESS API Token is required.',
            ], 422);
        }

        try {
            // 1. Live verify credentials against WESS
            $client = new WessClient($apiToken, $baseUrl);
            $authData = $client->testConnection();
            $branches = $client->getBranches();

            // If branch name not provided, find from branch list
            if (empty($branchName) && !empty($branches)) {
                foreach ($branches as $b) {
                    if ((string)$b['id'] === (string)$branchId) {
                        $branchName = $b['name'] ?? "Branch {$branchId}";
                        break;
                    }
                }
            }

            // Resolve calendar name and user ID if not provided directly
            $calendars = [];
            try {
                $calendarService = app(\App\Services\Ghl\GhlCalendarService::class);
                $calendars = $calendarService->getCalendars($locationId);
                if (!empty($calendarId)) {
                    foreach ($calendars as $cal) {
                        if ((string)$cal['id'] === (string)$calendarId) {
                            if (empty($calendarName)) {
                                $calendarName = $cal['name'] ?? 'Booking Calendar';
                            }
                            if (empty($calendarUserId)) {
                                $calendarUserId = $cal['user_id'] ?? '';
                            }
                            break;
                        }
                    }
                }
            } catch (Exception $e) {
                Log::info("Could not fetch CRM calendars during save: " . $e->getMessage());
            }

            // 2. Save / Update in database
            $config = WessConfig::updateOrCreate(
                ['location_id' => $locationId],
                [
                    'company_id'       => $companyId,
                    'api_token'        => $apiToken,
                    'base_url'         => $baseUrl,
                    'branch_id'        => $branchId,
                    'branch_name'      => $branchName,
                    'calendar_id'      => $calendarId,
                    'calendar_name'    => $calendarName,
                    'calendar_user_id' => $calendarUserId,
                    'is_sync_enabled'  => $isSyncEnabled,
                    'is_connected'     => true,
                ]
            );

            return response()->json([
                'success'      => true,
                'is_verified'  => true,
                'message'      => 'WESS credentials verified and saved successfully! Connected as: ' . ($authData['user']['name'] ?? 'Active Vendor'),
                'config'       => $config,
                'branches'     => $branches,
                'calendars'    => $calendars,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success'     => false,
                'is_verified' => false,
                'message'     => 'Failed to verify WESS credentials: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Fetch available CRM Calendars dynamically
     */
    public function getCalendars(Request $request)
    {
        $locationId = $request->input('locationId') ?: $request->input('location_id');

        if (empty($locationId)) {
            return response()->json([
                'success' => false,
                'message' => 'Location ID is required.',
            ], 422);
        }

        try {
            $calendarService = app(\App\Services\Ghl\GhlCalendarService::class);
            $calendars = $calendarService->getCalendars($locationId);

            return response()->json([
                'success'   => true,
                'calendars' => $calendars,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Could not fetch CRM calendars: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Master Toggle Switch: Instantly Pause or Resume Sync for this location
     */
    public function toggleSync(Request $request)
    {
        $locationId = $request->input('locationId') ?: $request->input('location_id');
        $enabled    = $request->boolean('is_sync_enabled');

        if (empty($locationId)) {
            return response()->json([
                'success' => false,
                'message' => 'Location ID is required.'
            ], 422);
        }

        $config = WessConfig::where('location_id', $locationId)->first();
        if (!$config) {
            return response()->json([
                'success' => false,
                'message' => 'Please save your WESS credentials first before enabling synchronization.'
            ], 422);
        }

        $config->is_sync_enabled = $enabled;
        $config->save();

        return response()->json([
            'success'         => true,
            'is_sync_enabled' => $config->is_sync_enabled,
            'message'         => $config->is_sync_enabled ? 'Sync is now ACTIVE for this location.' : 'Sync is now PAUSED for this location.',
        ]);
    }

    /**
     * Fetch paginated activity and webhook logs for the specific location (10 per page)
     */
    public function getLogs(Request $request)
    {
        $locationId = $request->input('locationId') ?: $request->input('location_id');
        $page       = max((int) $request->input('page', 1), 1);
        $search     = trim((string) $request->input('search', ''));
        $status     = $request->input('status');

        if (empty($locationId)) {
            return response()->json([
                'success' => false,
                'message' => 'Location ID is required to fetch activity logs.',
            ], 422);
        }

        $query = WebhookLog::where('location_id', $locationId)->latest();

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('event_type', 'like', "%{$search}%")
                  ->orWhere('source', 'like', "%{$search}%")
                  ->orWhere('endpoint', 'like', "%{$search}%")
                  ->orWhere('response_body', 'like', "%{$search}%")
                  ->orWhere('error_message', 'like', "%{$search}%");
            });
        }

        if ($status === 'success') {
            $query->whereIn('response_status', [200, 201]);
        } elseif ($status === 'error') {
            $query->where(function ($q) {
                $q->whereNotIn('response_status', [200, 201])
                  ->orWhereNotNull('error_message');
            });
        }

        $paginator = $query->paginate(10, ['*'], 'page', $page);

        $items = collect($paginator->items())->map(function ($log) {
            return [
                'id'               => $log->id,
                'source'           => $log->source,
                'event_type'       => $log->event_type ?: 'webhook_event',
                'endpoint'         => $log->endpoint ?: '—',
                'method'           => strtoupper((string) ($log->method ?: 'POST')),
                'response_status'  => $log->response_status,
                'is_success'       => in_array($log->response_status, [200, 201]) && empty($log->error_message),
                'payload'          => $log->payload,
                'response_body'    => $log->response_body,
                'error_message'    => $log->error_message,
                'created_at'       => $log->created_at ? $log->created_at->format('Y-m-d H:i:s') : '—',
                'created_at_human' => $log->created_at ? $log->created_at->diffForHumans() : '—',
            ];
        });

        return response()->json([
            'success' => true,
            'logs'    => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'from'         => $paginator->firstItem() ?: 0,
                'to'           => $paginator->lastItem() ?: 0,
                'data'         => $items,
            ],
        ]);
    }
}
