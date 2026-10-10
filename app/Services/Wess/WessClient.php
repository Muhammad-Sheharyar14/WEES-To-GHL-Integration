<?php

namespace App\Services\Wess;

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
        $this->baseUrl = rtrim($baseUrl ?: config('wess.default_base_url', 'https://api.prelive.wessconnect.net/api/v1/online'), '/');
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
        $this->baseUrl = rtrim($baseUrl, '/');
        return $this;
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
     * Execute an HTTP request with authentication and SSL options
     */
    public function request(string $method, string $url, array $params = [], array $body = [])
    {
        $client = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept'        => 'application/json',
        ])
        ->timeout($this->timeout)
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

        return $response;
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
     * Get branches list
     */
    public function getBranches(): array
    {
        $response = $this->request('GET', "{$this->baseUrl}/branches");

        if (!$response->successful()) {
            throw new Exception("Failed to fetch branches (HTTP {$response->status()}): " . ($response->json('message') ?? $response->body()));
        }

        return $response->json('data') ?? [];
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
     * Create customer in WESS
     */
    public function createCustomer(int|string $branchId, array $data): array
    {
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
     * Get appointments list for a branch
     */
    public function getAppointments(int|string $branchId, array $params = []): array
    {
        $response = $this->request('GET', "{$this->baseUrl}/branches/{$branchId}/appointments", $params);

        if (!$response->successful()) {
            return [];
        }

        return $response->json('data') ?? $response->json() ?? [];
    }
}
