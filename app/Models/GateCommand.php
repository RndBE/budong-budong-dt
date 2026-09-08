<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An order given to one spillway gate.
 *
 * The newest command before a moment is the standing one; nothing is ever
 * edited or deleted, so the record of who opened what, and when, survives.
 */
class GateCommand extends Model
{
    protected $guarded = [];

    protected $casts = [
        'gate' => 'integer',
        'opening' => 'float',
        'issued_at' => 'datetime',
    ];

    public function station(): BelongsTo
    {
        return $this->belongsTo(SensorStation::class, 'sensor_station_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
