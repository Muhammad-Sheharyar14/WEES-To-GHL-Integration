<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WessConfig extends Model
{
    use HasFactory;

    protected $table = 'wess_configs';

    protected $fillable = [
        'location_id',
        'company_id',
        'api_token',
        'base_url',
        'branch_id',
        'branch_name',
        'calendar_id',
        'calendar_name',
        'calendar_user_id',
        'is_sync_enabled',
        'is_connected',
        'last_synced_at',
    ];

    protected $casts = [
        'is_sync_enabled' => 'boolean',
        'is_connected'    => 'boolean',
        'last_synced_at'  => 'datetime',
    ];
}
