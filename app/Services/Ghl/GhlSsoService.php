<?php

namespace App\Services\Ghl;

use Illuminate\Support\Facades\Log;
use Exception;

class GhlSsoService
{
    protected string $sharedSecret;

    public function __construct()
    {
        $this->sharedSecret = config('ghl.shared_secret');
    }

    /**
     * Decrypt GHL SSO session token using the Marketplace Shared Secret Key
     * (AES-256-CBC with SHA256 derived key)
     */
    public function decryptSsoData(string $encryptedData): ?array
    {
        if (empty($encryptedData) || empty($this->sharedSecret)) {
            return null;
        }

        try {
            $key = hash('sha256', $this->sharedSecret, true);
            $parts = explode(':', $encryptedData);

            if (count($parts) === 2) {
                $iv = hex2bin($parts[0]);
                $ciphertext = hex2bin($parts[1]);
                $decrypted = openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
                if ($decrypted) {
                    return json_decode($decrypted, true);
                }
            }

            // Fallback base64 decoded string format
            $raw = base64_decode($encryptedData);
            if ($raw && strlen($raw) > 16) {
                $iv = substr($raw, 0, 16);
                $ciphertext = substr($raw, 16);
                $decrypted = openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
                if ($decrypted) {
                    return json_decode($decrypted, true);
                }
            }
        } catch (Exception $e) {
            Log::warning('GHL SSO Decryption Exception: ' . $e->getMessage());
        }

        return null;
    }
}
