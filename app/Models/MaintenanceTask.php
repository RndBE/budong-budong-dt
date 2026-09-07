<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaintenanceTask extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'scheduled_for' => 'date',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'last_message_at' => 'datetime',
    ];

    public function dam(): BelongsTo
    {
        return $this->belongsTo(Dam::class);
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(SensorStation::class, 'sensor_station_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return HasMany<MaintenanceMessage> */
    public function messages(): HasMany
    {
        return $this->hasMany(MaintenanceMessage::class)->orderBy('created_at');
    }
}
