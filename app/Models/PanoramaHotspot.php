<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PanoramaHotspot extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'yaw' => 'float',
        'pitch' => 'float',
        'sort_order' => 'integer',
    ];

    public function station(): BelongsTo
    {
        return $this->belongsTo(SensorStation::class, 'sensor_station_id');
    }

    public function targetStation(): BelongsTo
    {
        return $this->belongsTo(SensorStation::class, 'target_station_id');
    }
}
