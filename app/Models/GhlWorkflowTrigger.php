<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GhlWorkflowTrigger extends Model
{
    use HasFactory;

    protected $fillable = [
        'location_id',
        'company_id',
        'workflow_id',
        'trigger_id',
        'trigger_type',
        'event_type',
        'target_url',
        'raw_subscription_payload',
        'is_active',
    ];

    protected $casts = [
        'raw_subscription_payload' => 'array',
        'is_active'                => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForLocationAndType($query, string $locationId, string $triggerType = 'customer_created')
    {
        return $query->where('location_id', $locationId)
                     ->where('trigger_type', $triggerType)
                     ->where('is_active', true);
    }

    public function getFiltersAttribute(): array
    {
        return $this->raw_subscription_payload['triggerData']['filters']
            ?? $this->raw_subscription_payload['filters']
            ?? [];
    }
}
