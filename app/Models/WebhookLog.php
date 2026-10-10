<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebhookLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'source',
        'location_id',
        'email',
        'event_type',
        'endpoint',
        'method',
        'headers',
        'payload',
        'response_status',
        'response_body',
        'error_message',
    ];

    protected $casts = [
        'headers' => 'array',
        'payload' => 'array',
    ];
}
