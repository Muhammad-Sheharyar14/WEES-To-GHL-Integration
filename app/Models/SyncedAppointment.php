<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SyncedAppointment extends Model
{
    use HasFactory;

    protected $table = 'synced_appointments';

    protected $fillable = [
        'location_id',
        'wess_appointment_id',
        'ghl_appointment_id',
        'ghl_contact_id',
        'calendar_id',
        'assigned_user_id',
        'title',
        'status',
        'start_time',
        'end_time',
        'sync_hash',
        'last_synced_at',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
    ];
}
