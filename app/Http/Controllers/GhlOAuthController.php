<?php

namespace App\Http\Controllers;

use App\Services\Ghl\GhlAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Exception;

class GhlOAuthController extends Controller
{
    protected GhlAuthService $authService;

    public function __construct(GhlAuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Redirect user to GoHighLevel OAuth authorization page
     */
    public function connect(Request $request)
    {
        $url = $this->authService->getAuthorizationUrl();
        return redirect()->away($url);
    }

    /**
     * Handle GoHighLevel OAuth Callback
     */
    public function callback(Request $request)
    {
        $code = $request->query('code');
        $error = $request->query('error');
        $errorDescription = $request->query('error_description');

        if ($error) {
            Log::warning('GHL OAuth Callback Error', [
                'error'             => $error,
                'error_description' => $errorDescription,
            ]);
            return view('oauth_result', [
                'success' => false,
                'message' => $errorDescription ?: $error,
            ]);
        }

        if (!$code) {
            return view('oauth_result', [
                'success' => false,
                'message' => 'No authorization code received from GoHighLevel.',
            ]);
        }

        try {
            // Exchange code with GHL
            $tokenRecord = $this->authService->exchangeCode($code);

            $isAgency = ($tokenRecord->user_type === 'Company') || (empty($tokenRecord->location_id) && !empty($tokenRecord->company_id));

            return view('oauth_result', [
                'success'    => true,
                'isAgency'   => $isAgency,
                'message'    => $isAgency 
                    ? 'WESS Integration Agency Connected Successfully! You can now manage WESS settings for each sub-account directly inside their sub-account custom page.'
                    : 'WESS Integration Sub-Account Connected Successfully!',
                'token'      => $tokenRecord,
                'locationId' => $tokenRecord->location_id,
                'companyId'  => $tokenRecord->company_id,
                'userType'   => $tokenRecord->user_type,
            ]);
        } catch (Exception $e) {
            Log::error('OAuth Callback Exception: ' . $e->getMessage());
            return view('oauth_result', [
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage(),
            ]);
        }
    }
}
