<?php

namespace App\Services\Wess;

use App\Exceptions\WessRateLimitException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class WessClient
{
    protected string $token;
    protected string $baseUrl;
    protected int $timeout;

    public function __construct(?string $token = null, ?string $baseUrl = null, int $timeout = 15)
    {
        $this->token   = $token ?: config('wess.default_token', '');
        $this->baseUrl = $this->normalizeBaseUrl($baseUrl ?: config('wess.default_base_url', 'https://api.wessconnect.com/api/v1/online'));
        $this->timeout = $timeout;
    }

    /**
     * Set token dynamically
     */
    public function setToken(string $token): self
    {
        $this->token = $token;
        return $this;
    }

    /**
     * Set base URL dynamically
     */
    public function setBaseUrl(string $baseUrl): self
    {
        $this->baseUrl = $this->normalizeBaseUrl($baseUrl);
        return $this;
    }

    /**
     * Normalize base URL to ensure standard /api/v1/online structure
     */
    protected function normalizeBaseUrl(string $url): string
    {
        $trimmed = rtrim($url, '/');
        if (preg_match('#/api$#', $trimmed)) {
            return $trimmed . '/v1/online';
        }
        if (preg_match('#/api/v1$#', $trimmed)) {
            return $trimmed . '/online';
        }
        return $trimmed;
    }

    /**
     * Get root API URL (stripping /online if needed for /user endpoint)
     */
    protected function getRootUrl(): string
    {
        // If baseUrl ends with /api/v1/online, root is /api/v1
        return preg_replace('#/online$#', '', $this->baseUrl);
    }

    /**
     * Execute an HTTP request with authentication, SSL options, and rate-limit backoff handling.
     * Rate Limit: 60 requests per minute per authenticated client (Official WESS API Spec)
     */
    public function request(string $method, string $url, array $params = [], array $body = [], int $maxRetries = 2)
    {
        $attempt = 0;

        while ($attempt <= $maxRetries) {
            $attempt++;

            // Client-side rate control: pace calls if hitting rapidly
            $this->throttleClientRequest();

            $client = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Accept'        => 'application/json',
            ])
            ->timeout($this->timeout)
            ->withOptions(['version' => 1.1])
            ->withoutVerifying(); // Allow self-signed or prelive certificates

            if (!empty($params)) {
                $client = $client->withQueryParameters($params);
            }

            $response = match (strtoupper($method)) {
                'GET'    => $client->get($url),
                'POST'   => $client->asJson()->post($url, $body),
                'PATCH'  => $client->asJson()->patch($url, $body),
                'PUT'    => $client->asJson()->put($url, $body),
                'DELETE' => $client->delete($url),
                default  => throw new Exception("Unsupported HTTP method: {$method}"),
            };

            // Handle WESS HTTP 429 Too Many Attempts
            if ($response->status() === 429) {
                $retryAfter = (int) ($response->header('Retry-After') ?: 5);

                Log::warning("WESS API Rate Limit (HTTP 429) on [{$method} {$url}]. Retry-After: {$retryAfter}s. (Attempt {$attempt}/{$maxRetries})");

                // Auto-retry if wait time is short (<= 5s) and retries remain
                if ($attempt <= $maxRetries && $retryAfter <= 5) {
                    sleep($retryAfter);
                    continue;
                }

                throw new WessRateLimitException(
                    "WESS API rate limit reached (60 requests/minute). Please wait {$retryAfter} seconds before trying again.",
                    $retryAfter
                );
            }

            return $response;
        }

        return $response;
    }

    /**
     * Proactive client-side throttling to pace calls and avoid exceeding 60 req/min
     */
    protected function throttleClientRequest(): void
    {
        try {
            if (empty($this->token)) {
                return;
            }
            $tokenKey = 'wess_rl_' . substr(md5($this->token), 0, 10);
            $count = (int) Cache::get($tokenKey, 0);

            // If we are nearing the 60 requests/minute threshold, pace requests with a micro-delay
            if ($count >= 58) {
                Log::info("Approaching WESS rate limit (58/60 calls). Pacing request by 1 second.");
                usleep(1000000); // 1.0s pause
            }

            Cache::add($tokenKey, 0, 60);
            Cache::increment($tokenKey);
        } catch (\Throwable $e) {
            // Non-blocking fallback if cache driver is unavailable
        }
    }

    /**
     * Test connection and retrieve authenticated user / vendor info
     */
    public function testConnection(): array
    {
        $rootUrl = $this->getRootUrl();
        $response = $this->request('GET', "{$rootUrl}/user");

        if (!$response->successful()) {
            throw new Exception("WESS Authentication Failed (HTTP {$response->status()}): " . ($response->json('message') ?? $response->body()));
        }

        $userData = $response->json();
        return [
            'success'     => true,
            'user'        => $userData['data'] ?? [],
            'permissions' => $userData['permissions'] ?? [],
            'roles'       => $userData['roles'] ?? [],
        ];
    }

    /**
     * Fetch all items across all pages from a paginated WESS endpoint
     *
     * @param string $endpoint Relative endpoint e.g. "branches" or "branches/{branchId}/appointments"
     * @param array $params Query parameters (filters, sort, etc.)
     * @param int $maxPages Safety ceiling to avoid infinite loops (default 100 pages = up to 10,000 items)
     * @param int|null $maxItems Optional limit on total items to retrieve (stops early when reached)
     * @return array All merged items from the 'data' key across all pages
     */
    public function fetchAllPages(string $endpoint, array $params = [], int $maxPages = 100, ?int $maxItems = null): array
    {
        $allData = [];
        $page = (int) ($params['page'] ?? 1);
        $perPage = (int) ($params['per_page'] ?? 100);

        // If maxItems is specified and smaller than per_page, request fewer items to optimize bandwidth
        if ($maxItems !== null && $maxItems > 0 && $maxItems < $perPage) {
            $perPage = $maxItems;
        }
        $params['per_page'] = $perPage;

        do {
            $params['page'] = $page;
            $response = $this->request('GET', "{$this->baseUrl}/{$endpoint}", $params);

            if (!$response->successful()) {
                Log::warning("[WESS] Failed to fetch page {$page} for {$endpoint}: HTTP {$response->status()}");
                break;
            }

            $json = $response->json();

            // If endpoint returned a plain array directly (non-paginated collection)
            if (is_array($json) && !isset($json['data']) && !empty($json)) {
                return $json;
            }

            $data = $json['data'] ?? [];

            if (empty($data) || !is_array($data)) {
                break;
            }

            $allData = array_merge($allData, $data);

            // If maxItems limit reached, stop and return
            if ($maxItems !== null && count($allData) >= $maxItems) {
                return array_slice($allData, 0, $maxItems);
            }

            // Check official WESS pagination: links.next > meta.last_page > count fallback
            $lastPage    = isset($json['meta']['last_page']) ? (int) $json['meta']['last_page'] : null;
            $hasNextLink = isset($json['links']['next']) ? !empty($json['links']['next']) : null;

            if ($hasNextLink !== null) {
                $hasNext = $hasNextLink;
            } elseif ($lastPage !== null) {
                $hasNext = ($page < $lastPage);
            } else {
                $hasNext = (count($data) >= $perPage);
            }

            Log::debug("[WESS] Fetched page {$page}" . ($lastPage ? "/{$lastPage}" : "") . " for {$endpoint} (" . count($data) . " items, total: " . count($allData) . ")");

            $page++;
        } while ($hasNext && $page <= $maxPages);

        return $allData;
    }

    /**
     * Get branches list (fetches all pages)
     */
    public function getBranches(array $params = []): array
    {
        return $this->fetchAllPages('branches', $params);
    }

    /**
     * Get single branch details
     */
    public function getBranch(int|string $branchId): array
    {
        $response = $this->request('GET', "{$this->baseUrl}/branches/{$branchId}");

        if (!$response->successful()) {
            throw new Exception("Failed to fetch branch {$branchId} (HTTP {$response->status()}): " . ($response->json('message') ?? $response->body()));
        }

        return $response->json() ?? [];
    }

    /**
     * Lookup customer by phone number
     */
    public function lookupCustomerByPhone(string $phoneNumber): ?array
    {
        // Strip non-digits if needed, keeping country code
        $cleanedPhone = preg_replace('/[^0-9]/', '', $phoneNumber);
        $response = $this->request('GET', "{$this->baseUrl}/customers/lookup/phone-number/{$cleanedPhone}");

        if ($response->successful()) {
            $data = $response->json();
            if (is_array($data) && !empty($data[0])) {
                return $data[0];
            }
        }

        return null;
    }

    /**
     * Parse raw phone number into country code and local mobile number for WESS
     */
    public static function parsePhoneNumber(string $phone): array
    {
        $raw = trim($phone);
        $hasPlus = str_starts_with($raw, '+');
        $digits = preg_replace('/[^0-9]/', '', $raw);

        $countryCode = '65'; // Default to Singapore (+65)
        $mobile = $digits;

        if ($hasPlus) {
            // E.g., +6591234567, +60123456789, +15551234567, etc.
            if (str_starts_with($digits, '65') && strlen($digits) >= 10) {
                $countryCode = '65';
                $mobile = substr($digits, 2);
            } elseif (str_starts_with($digits, '60') && strlen($digits) >= 11) {
                $countryCode = '60';
                $mobile = substr($digits, 2);
            } elseif (str_starts_with($digits, '1') && strlen($digits) == 11) {
                $countryCode = '1';
                $mobile = substr($digits, 1);
            } elseif (str_starts_with($digits, '44') && strlen($digits) >= 11) {
                $countryCode = '44';
                $mobile = substr($digits, 2);
            } elseif (str_starts_with($digits, '61') && strlen($digits) >= 11) {
                $countryCode = '61';
                $mobile = substr($digits, 2);
            } elseif (str_starts_with($digits, '91') && strlen($digits) >= 12) {
                $countryCode = '91';
                $mobile = substr($digits, 2);
            }
        } else {
            // No leading plus: e.g. 6591234567 or 91234567
            if (str_starts_with($digits, '65') && strlen($digits) == 10) {
                $countryCode = '65';
                $mobile = substr($digits, 2);
            } elseif (str_starts_with($digits, '60') && (strlen($digits) == 11 || strlen($digits) == 12)) {
                $countryCode = '60';
                $mobile = substr($digits, 2);
            } elseif (strlen($digits) == 8) {
                $countryCode = '65';
                $mobile = $digits;
            }
        }

        return [
            'country_code' => $countryCode,
            'mobile'       => $mobile,
        ];
    }

    /**
     * Create customer in WESS
     */
    public function createCustomer(int|string $branchId, array $data): array
    {
        // Automatically normalize phone fields for WESS API requirements
        if (empty($data['phone_mobile']) && (!empty($data['phone_number']) || !empty($data['phone']))) {
            $rawPhone = $data['phone_number'] ?? $data['phone'];
            $parsed = self::parsePhoneNumber($rawPhone);
            $data['phone_mobile_country_code'] = $data['phone_mobile_country_code'] ?? $parsed['country_code'];
            $data['phone_mobile']              = $parsed['mobile'];
        }

        // Clean up internal non-WESS aliases
        unset($data['phone_number'], $data['phone']);

        $response = $this->request('POST', "{$this->baseUrl}/branches/{$branchId}/customers", [], $data);

        if (!$response->successful()) {
            $msg = $response->json('message') ?? $response->body();
            $errors = $response->json('errors');
            if ($errors) {
                $msg .= ': ' . json_encode($errors);
            }
            throw new Exception("Failed to create customer in WESS (HTTP {$response->status()}): {$msg}");
        }

        return $response->json('data') ?? $response->json();
    }

    /**
     * Get customer details by ID
     */
    public function getCustomer(int|string $branchId, int|string $customerId): array
    {
        $response = $this->request('GET', "{$this->baseUrl}/branches/{$branchId}/customers/{$customerId}");

        if (!$response->successful()) {
            throw new Exception("Failed to fetch customer {$customerId} (HTTP {$response->status()}): " . ($response->json('message') ?? $response->body()));
        }

        return $response->json() ?? [];
    }

    /**
     * List customers belonging to or shared with a branch (Section 3.4 of API docs)
     * Note: Requires 'online.core.customer.index' / administrative permission from WESS admin.
     *
     * @param int|string $branchId
     * @param array $params (page, per_page, filter[full_name], filter[phone_mobile], etc.)
     * @param bool $allPages Whether to automatically loop and retrieve all pages
     * @return array
     */
    public function getBranchCustomers(int|string $branchId, array $params = [], bool $allPages = true): array
    {
        if ($allPages) {
            return $this->fetchAllPages("branches/{$branchId}/customers", $params);
        }

        $response = $this->request('GET', "{$this->baseUrl}/branches/{$branchId}/customers", $params);

        if (!$response->successful()) {
            throw new Exception("Failed to list branch customers (HTTP {$response->status()}): " . ($response->json('message') ?? $response->body()));
        }

        return $response->json() ?? [];
    }

    /**
     * Get services available for a branch
     */
    public function getServices(int|string $branchId): array
    {
        $response = $this->request('GET', "{$this->baseUrl}/branches/{$branchId}/appointments/services");

        if (!$response->successful()) {
            return [];
        }

        return $response->json('data') ?? [];
    }

    /**
     * Get therapists / employees available for a branch
     */
    public function getEmployees(int|string $branchId): array
    {
        $response = $this->request('GET', "{$this->baseUrl}/branches/{$branchId}/appointments/employees");

        if (!$response->successful()) {
            return [];
        }

        return $response->json('data') ?? [];
    }

    /**
     * Get available appointment time slots
     */
    public function getTimeSlots(int|string $branchId, string $date, array $productIds = []): array
    {
        $params = ['date' => $date];
        foreach ($productIds as $idx => $id) {
            $params["product_ids[{$idx}]"] = $id;
        }

        $response = $this->request('GET', "{$this->baseUrl}/branches/{$branchId}/appointments/time-slots", $params);

        if (!$response->successful()) {
            return [];
        }

        return $response->json('data') ?? $response->json() ?? [];
    }

    /**
     * Book appointment in WESS
     */
    public function bookAppointment(int|string $branchId, array $payload): array
    {
        $response = $this->request('POST', "{$this->baseUrl}/branches/{$branchId}/appointments", [], $payload);

        if (!$response->successful()) {
            $msg = $response->json('message') ?? $response->body();
            $errors = $response->json('errors');
            if ($errors) {
                $msg .= ': ' . json_encode($errors);
            }
            throw new Exception("Failed to book appointment in WESS (HTTP {$response->status()}): {$msg}");
        }

        return $response->json('data') ?? $response->json();
    }

    /**
     * Cancel appointment in WESS
     */
    public function cancelAppointment(int|string $branchId, int|string $appointmentId, int|string $customerId): array
    {
        $response = $this->request('PATCH', "{$this->baseUrl}/branches/{$branchId}/appointments/{$appointmentId}/cancel", [], [
            'customer_id' => $customerId,
        ]);

        if (!$response->successful()) {
            throw new Exception("Failed to cancel appointment in WESS (HTTP {$response->status()}): " . ($response->json('message') ?? $response->body()));
        }

        return $response->json() ?? ['success' => true];
    }

    /**
     * Get appointments list for a branch (fetches all pages by default)
     *
     * @param int|string $branchId
     * @param array $params Query filters (date_from, date_to, status, etc.)
     * @param bool $allPages Whether to automatically loop and retrieve all pages
     * @param int|null $limit Optional item limit
     * @return array
     */
    public function getAppointments(int|string $branchId, array $params = [], bool $allPages = true, ?int $limit = null): array
    {
        if ($allPages) {
            return $this->fetchAllPages("branches/{$branchId}/appointments", $params, 100, $limit);
        }

        $response = $this->request('GET', "{$this->baseUrl}/branches/{$branchId}/appointments", $params);

        if (!$response->successful()) {
            return [];
        }

        return $response->json('data') ?? $response->json() ?? [];
    }
}
