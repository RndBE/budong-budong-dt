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
        'meta' => 'array',
        'sort_order' => 'integer',
    ];

    /** Is this hotspot a row of monitoring plots? */
    public function isPlot(): bool
    {
        return $this->type === 'plot' && $this->stakeCodes() !== [];
    }

    /**
     * The stake codes a monitoring plot stands for.
     *
     * The stakes are not rows of their own: they sit in a regular row along
     * the middle of the plot, so their positions are derived from the plot's
     * centre and their names from its code. One row to place, five stakes
     * drawn.
     *
     * @return list<string>
     */
    public function stakeCodes(): array
    {
        $meta = $this->meta ?? [];
        $code = $meta['code'] ?? null;
        $count = (int) ($meta['stakes'] ?? 0);

        if (! $code || $count < 1) {
            return [];
        }

        return array_map(fn (int $number) => "{$code}-{$number}", range(1, $count));
    }

    /** Is this hotspot a section of piezometers? */
    public function isSection(): bool
    {
        return $this->type === 'piezo' && $this->piezoPoints() !== [];
    }

    /**
     * The instruments a section stands for.
     *
     * Unlike a row of monitoring plots, these are **listed rather than
     * counted**: a piezometer is buried, so nothing of it shows in the
     * photograph and there is no regular spacing to derive it from — every
     * one of them sits at its own elevation and its own distance from the
     * axis. `offset` is metres from the axis, positive downstream; `kind`
     * says whether it is in the foundation or in the fill.
     *
     * @return list<array<string, mixed>>
     */
    public function piezoPoints(): array
    {
        $points = $this->meta['points'] ?? [];

        if (! is_array($points)) {
            return [];
        }

        return array_values(array_filter(
            $points,
            fn ($point) => is_array($point)
                && isset($point['code'], $point['elevation'], $point['offset'])
        ));
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(SensorStation::class, 'sensor_station_id');
    }

    public function targetStation(): BelongsTo
    {
        return $this->belongsTo(SensorStation::class, 'target_station_id');
    }
}
