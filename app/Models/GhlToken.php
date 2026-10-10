<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class GhlToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'location_id',
        'company_id',
        'user_id',
        'user_type',
        'access_token',
        'refresh_token',
        'expires_at',
        'scope',
        'is_active',
    ];

    protected $casts = [
        'access_token'  => 'encrypted',
        'refresh_token' => 'encrypted',
        'expires_at'    => 'datetime',
        'is_active'     => 'boolean',
    ];

    /**
     * Check if token is expired or expiring within buffer seconds (default 300s = 5m)
     */
    public function isExpired(int $bufferSeconds = 300): bool
    {
        if (!$this->expires_at) {
            return true;
        }

        return Carbon::now()->addSeconds($bufferSeconds)->greaterThanOrEqualTo($this->expires_at);
    }

    /**
     * Decrypt an encrypted string attribute with fallback for legacy plaintext.
     */
    public function fromEncryptedString($value)
    {
        if (empty($value)) {
            return $value;
        }

        try {
            return static::currentEncrypter()->decrypt($value, false);
        } catch (\Throwable $e) {
            // Fallback for existing plaintext records from before encryption
            return $value;
        }
    }
}
