<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SensorMetric extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'states' => 'array',
        'decimals' => 'integer',
        'is_primary' => 'boolean',
        'normal_min' => 'float',
        'normal_max' => 'float',
        'warning_threshold' => 'float',
        'alert_threshold' => 'float',
        'critical_threshold' => 'float',
        'sort_order' => 'integer',
    ];

    public function station(): BelongsTo
    {
        return $this->belongsTo(SensorStation::class, 'sensor_station_id');
    }

    /**
     * The word a state metric's number stands for.
     *
     * A reading is always a number; "Kondisi Pintu" is a word. The metric
     * carries the map, so the series still charts and still has thresholds
     * while the panels print something a person can read. Null for the
     * ordinary quantities, which have nothing to look up.
     */
    public function stateLabel(?float $value): ?string
    {
        if ($value === null || ! $this->states) {
            return null;
        }

        return $this->states[(string) (int) round($value)] ?? null;
    }

    /** Classify a value against this metric's thresholds. */
    public function statusFor(?float $value): string
    {
        if ($value === null) {
            return 'offline';
        }

        foreach (['critical_threshold' => 'bahaya', 'alert_threshold' => 'siaga', 'warning_threshold' => 'waspada'] as $column => $status) {
            $threshold = $this->{$column};
            if ($threshold !== null && $value >= $threshold) {
                return $status;
            }
        }

        if ($this->normal_min !== null && $value < $this->normal_min) {
            return 'waspada';
        }

        if ($this->normal_max !== null && $value > $this->normal_max) {
            return 'waspada';
        }

        return 'normal';
    }
}
