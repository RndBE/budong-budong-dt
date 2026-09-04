<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SensorStation extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'elevation' => 'float',
        'map_x' => 'float',
        'map_y' => 'float',
        'panorama_yaw' => 'float',
        'panorama_pitch' => 'float',
        'panorama_north_offset' => 'float',
        'sphere_yaw' => 'float',
        'sphere_pitch' => 'float',
        'is_online' => 'boolean',
        'installed_on' => 'date',
        'calibrated_on' => 'date',
        'meta' => 'array',
    ];

    /** Severity ranking used for map/summary roll-ups. */
    public const STATUS_WEIGHT = [
        'normal' => 0,
        'waspada' => 1,
        'siaga' => 2,
        'bahaya' => 3,
        'offline' => 1,
    ];

    public function dam(): BelongsTo
    {
        return $this->belongsTo(Dam::class);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(SensorMetric::class)->orderBy('sort_order');
    }

    public function readings(): HasMany
    {
        return $this->hasMany(SensorReading::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    public function hotspots(): HasMany
    {
        return $this->hasMany(PanoramaHotspot::class)->orderBy('sort_order');
    }

    public function maintenanceTasks(): HasMany
    {
        return $this->hasMany(MaintenanceTask::class);
    }

    public function panoramaUrl(): ?string
    {
        return $this->panorama ? asset("assets/panorama/{$this->panorama}.webp") : null;
    }

    public function panoramaThumbUrl(): ?string
    {
        return $this->panorama ? asset("assets/panorama/thumb/{$this->panorama}.webp") : null;
    }

    /** Low-resolution texture shown while the HD sphere downloads. */
    public function panoramaPreviewUrl(): ?string
    {
        return $this->panorama ? asset("assets/panorama/preview/{$this->panorama}.webp") : null;
    }

    public function latestReading(string $metricKey): ?SensorReading
    {
        return $this->readings()
            ->where('metric_key', $metricKey)
            ->orderByDesc('recorded_at')
            ->first();
    }
}
