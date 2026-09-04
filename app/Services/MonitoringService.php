<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\Dam;
use App\Models\MaintenanceTask;
use App\Models\SensorMetric;
use App\Models\SensorReading;
use App\Models\SensorStation;
use App\Services\Telemetry\TelemetryProvider;
use App\Services\Weather\WeatherProvider;
use App\Support\SolarClock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Assembles every payload the dashboard renders: header environment strip,
 * right-hand summary panel, map markers and the per-station detail shown
 * beside the 360 viewer.
 */
class MonitoringService
{
    private ?Dam $dam = null;

    public function __construct(
        private readonly TelemetryProvider $telemetry,
        private readonly WeatherProvider $weather,
    ) {}

    public function dam(): Dam
    {
        return $this->dam ??= Dam::query()->where('code', config('dam.code'))->firstOrFail();
    }

    public function solarClock(): SolarClock
    {
        $dam = $this->dam();

        return new SolarClock($dam->latitude, $dam->longitude, $dam->timezone);
    }

    /* ------------------------------------------------------------------ *
     |  Environment: clock, sun phase, background scene, weather
     * ------------------------------------------------------------------ */

    public function environment(?CarbonInterface $at = null): array
    {
        $dam = $this->dam();
        $sun = $this->solarClock()->state($at);
        /** @var CarbonImmutable $now */
        $now = $sun['now'];

        return [
            'dam' => [
                'code' => $dam->code,
                'name' => $dam->name,
                'authority' => $dam->authority,
                'timezone' => $dam->timezone,
            ],
            'offset_minutes' => (int) round($now->utcOffset()),
            'clock' => [
                'iso' => $now->toIso8601String(),
                'time' => $now->format('H:i:s'),
                'date' => $this->formatDate($now),
                'zone_label' => $this->zoneLabel($now),
            ],
            'sun' => [
                'elevation' => $sun['elevation'],
                'azimuth' => $sun['azimuth'],
                'phase' => $sun['phase'],
                'phase_label' => $sun['phase_label'],
                'sunrise' => $sun['sunrise']?->format('H:i'),
                'sunset' => $sun['sunset']?->format('H:i'),
                'is_morning' => $sun['is_morning'],
            ],
            'scene' => [
                'primary' => $sun['scene']['primary'],
                'secondary' => $sun['scene']['secondary'],
                'mix' => $sun['scene']['mix'],
                'grade' => $sun['scene']['grade'],
                'daylight' => $sun['scene']['daylight'],
                'assets' => collect(config('dam.map.phases'))
                    ->mapWithKeys(fn (string $phase) => [
                        $phase => asset(config('dam.map.asset_path')."/map-{$phase}.webp"),
                    ])->all(),
            ],
            'weather' => $this->weather->current($dam),
            'stage' => $this->stage(),
        ];
    }

    /**
     * The panorama the digital twin stage opens on, plus its camera defaults.
     * Station pins are placed inside this sphere.
     */
    public function stage(): array
    {
        $base = $this->sphereOrigin();

        return [
            'base' => $base ? [
                'code' => $base->code,
                'name' => $base->name,
                'panorama' => [
                    'url' => $base->panoramaUrl(),
                    'preview' => $base->panoramaPreviewUrl(),
                    'thumb' => $base->panoramaThumbUrl(),
                    'yaw' => (float) $base->panorama_yaw,
                    'pitch' => (float) $base->panorama_pitch,
                ],
                'phases' => $this->basePhases($base),
            ] : null,
            'default_zoom' => (int) config('dam.stage.default_zoom', 45),
            'default_pitch' => (float) config('dam.stage.default_pitch', 0),
        ];
    }

    /**
     * Every lighting state of one day, sampled every `step` minutes.
     *
     * The stage can then scrub or fast-forward through a day without asking the
     * server for each frame — and without re-implementing the solar maths in
     * JavaScript, which would drift from `SolarClock`.
     */
    public function dayCurve(?string $date = null, int $step = 10): array
    {
        $dam = $this->dam();
        $clock = $this->solarClock();
        $step = max(2, min(60, $step));

        $day = $date
            ? CarbonImmutable::parse($date, $dam->timezone)->startOfDay()
            : CarbonImmutable::now($dam->timezone)->startOfDay();

        $samples = [];
        for ($minute = 0; $minute <= 1440; $minute += $step) {
            $moment = $day->addMinutes(min($minute, 1439));
            $position = $clock->position($moment);
            $scene = $clock->scene($position['elevation'], $position['is_morning']);

            $samples[] = [
                'minute' => $minute,
                'elevation' => round($position['elevation'], 2),
                'phase' => $scene['phase'],
                'phase_label' => $scene['phase_label'],
                'primary' => $scene['primary'],
                'secondary' => $scene['secondary'],
                'mix' => $scene['mix'],
                'grade' => $scene['grade'],
                'daylight' => $scene['daylight'],
            ];
        }

        return [
            'date' => $day->toDateString(),
            'timezone' => $dam->timezone,
            'zone_label' => $this->zoneLabel($day),
            'step_minutes' => $step,
            'sunrise' => $clock->eventTime($day, true)?->format('H:i'),
            'sunset' => $clock->eventTime($day, false)?->format('H:i'),
            'assets' => collect(config('dam.map.phases'))
                ->mapWithKeys(fn (string $phase) => [
                    $phase => asset(config('dam.map.asset_path')."/map-{$phase}.webp"),
                ])->all(),
            'samples' => $samples,
        ];
    }

    /* ------------------------------------------------------------------ *
     |  Right panel summary
     * ------------------------------------------------------------------ */

    public function dashboard(): array
    {
        $dam = $this->dam();
        $stations = $this->stationsWithMetrics();
        $latest = $this->telemetry->latestForStations($stations);

        $evaluated = $this->evaluate($stations, $latest);

        return [
            'updated_at' => CarbonImmutable::now($dam->timezone)->toIso8601String(),
            'dam' => [
                'code' => $dam->code,
                'name' => $dam->name,
                'authority' => $dam->authority,
                'normal_water_level' => $dam->normal_water_level,
                'flood_water_level' => $dam->flood_water_level,
                'crest_elevation' => $dam->crest_elevation,
            ],
            'primary' => $this->primaryParameters($stations, $latest),
            'health' => $this->health($evaluated),
            'recent' => $this->recentReadings($stations, $latest),
            'alerts' => $this->activeAlerts(),
            'system' => $this->systemStatus($stations, $evaluated),
        ];
    }

    /**
     * Time-of-day textures for the base panorama.
     *
     * All four are rendered from the same viewpoint (`Transitions/Base Dam`)
     * and built by `tools/build_panorama_phases.py`, so the stage can cross-fade
     * between them. A phase without a file falls back to the daylight sphere,
     * so a half-built asset folder still works.
     *
     * @return array<string, array{url: string, preview: string}>
     */
    private function basePhases(SensorStation $base): array
    {
        $day = ['url' => $base->panoramaUrl(), 'preview' => $base->panoramaPreviewUrl()];

        return collect(config('dam.map.phases'))
            ->mapWithKeys(function (string $phase) use ($base, $day) {
                $file = "assets/panorama/{$base->panorama}-{$phase}.webp";

                return [$phase => file_exists(public_path($file)) ? [
                    'url' => asset($file),
                    'preview' => asset("assets/panorama/preview/{$base->panorama}-{$phase}.webp"),
                ] : $day];
            })
            ->all();
    }

    /** @return Collection<int, SensorStation> */
    public function stationsWithMetrics(): Collection
    {
        return SensorStation::query()
            ->where('dam_id', $this->dam()->id)
            ->with('metrics')
            ->orderBy('name')
            ->get();
    }

    /* ------------------------------------------------------------------ *
     |  Map markers
     * ------------------------------------------------------------------ */

    public function markers(): array
    {
        $stations = $this->stationsWithMetrics();
        $latest = $this->telemetry->latestForStations($stations);
        $origin = $this->sphereOrigin();

        return $stations->map(function (SensorStation $station) use ($latest, $origin) {
            $readings = $latest[$station->id] ?? [];
            $primary = $station->metrics->firstWhere('is_primary', true) ?? $station->metrics->first();
            $status = $this->stationStatus($station, $readings);

            $value = $primary ? ($readings[$primary->key]['value'] ?? null) : null;

            return [
                'id' => $station->id,
                'code' => $station->code,
                'name' => $station->name,
                'short_name' => $station->short_name ?? $station->name,
                'type' => $station->type,
                'type_label' => $this->typeLabel($station->type),
                'group' => $station->group,
                'zone' => $station->zone,
                'x' => $station->map_x,
                'y' => $station->map_y,
                'sphere' => $this->spherePosition($station, $origin),
                'status' => $status,
                'status_label' => config("dam.statuses.{$status}.label", 'Normal'),
                'is_online' => $station->is_online,
                'has_panorama' => (bool) $station->panorama,
                'panorama_url' => $station->panoramaUrl(),
                'thumb_url' => $station->panoramaThumbUrl(),
                // Enough for the stage to swap spheres on click, without
                // waiting for the full station payload first.
                'panorama' => $station->panorama ? [
                    'url' => $station->panoramaUrl(),
                    'preview' => $station->panoramaPreviewUrl(),
                    'yaw' => (float) $station->panorama_yaw,
                    'pitch' => (float) $station->panorama_pitch,
                ] : null,
                'primary_metric' => $primary?->key,
                'caption' => $primary && $value !== null
                    ? $this->formatValue($value, $primary)
                    : ($station->is_online ? 'Aktif' : 'Offline'),
            ];
        })->values()->all();
    }

    /* ------------------------------------------------------------------ *
     |  Station detail (right panel while the 360 viewer is open)
     * ------------------------------------------------------------------ */

    public function station(string $code, string $range = '24h'): array
    {
        /** @var SensorStation $station */
        $station = SensorStation::query()
            ->where('dam_id', $this->dam()->id)
            ->where('code', $code)
            ->with(['metrics', 'hotspots.targetStation:id,code,name'])
            ->firstOrFail();

        $readings = $this->telemetry->latest($station);
        $status = $this->stationStatus($station, $readings);
        [$from, $to] = $this->rangeBounds($range);

        $metrics = $station->metrics->map(function (SensorMetric $metric) use ($station, $readings, $from, $to) {
            $value = $readings[$metric->key]['value'] ?? null;
            $recordedAt = $readings[$metric->key]['recorded_at'] ?? null;
            $previous = $this->previousValue($station, $metric->key, $recordedAt);

            return [
                'key' => $metric->key,
                'label' => $metric->label,
                'unit' => $metric->unit,
                'value' => $value,
                'formatted' => $value === null ? '—' : $this->formatValue($value, $metric, false),
                'display' => $value === null ? '—' : $this->formatValue($value, $metric),
                'status' => $metric->statusFor($value === null ? null : (float) $value),
                'is_primary' => $metric->is_primary,
                'chart_type' => $metric->chart_type,
                'thresholds' => [
                    'normal_min' => $metric->normal_min,
                    'normal_max' => $metric->normal_max,
                    'warning' => $metric->warning_threshold,
                    'alert' => $metric->alert_threshold,
                    'critical' => $metric->critical_threshold,
                ],
                'trend' => $this->trend($value, $previous, $metric),
                'recorded_at' => $recordedAt?->toIso8601String(),
                'recorded_label' => $recordedAt ? $this->localTime($recordedAt) : null,
                'series' => $this->telemetry->series($station, $metric->key, $from, $to, 180),
            ];
        })->values()->all();

        return [
            'code' => $station->code,
            'name' => $station->name,
            'short_name' => $station->short_name ?? $station->name,
            'type' => $station->type,
            'type_label' => $this->typeLabel($station->type),
            'group' => $station->group,
            'zone' => $station->zone,
            'status' => $status,
            'status_label' => config("dam.statuses.{$status}.label", 'Normal'),
            'is_online' => $station->is_online,
            'description' => $station->description,
            'vendor' => $station->vendor,
            'model' => $station->model,
            'elevation' => $station->elevation,
            'coordinates' => [
                'latitude' => $station->latitude,
                'longitude' => $station->longitude,
            ],
            'installed_on' => $station->installed_on?->format('d M Y'),
            'calibrated_on' => $station->calibrated_on?->format('d M Y'),
            'range' => $range,
            'panorama' => [
                'url' => $station->panoramaUrl(),
                'preview' => $station->panoramaPreviewUrl(),
                'thumb' => $station->panoramaThumbUrl(),
                'yaw' => $station->panorama_yaw,
                'pitch' => $station->panorama_pitch,
                'north_offset' => $station->panorama_north_offset,
            ],
            'hotspots' => $station->hotspots->map(fn ($hotspot) => [
                'id' => $hotspot->id,
                'type' => $hotspot->type,
                'label' => $hotspot->label,
                'description' => $hotspot->description,
                'metric_key' => $hotspot->metric_key,
                'yaw' => $hotspot->yaw,
                'pitch' => $hotspot->pitch,
                'target' => $hotspot->targetStation?->only(['code', 'name']),
            ])->values()->all(),
            'metrics' => $metrics,
            'meta' => $station->meta ?? [],
            'alerts' => Alert::query()
                ->where('sensor_station_id', $station->id)
                ->orderByDesc('triggered_at')
                ->limit(5)
                ->get()
                ->map(fn (Alert $alert) => $this->presentAlert($alert))
                ->all(),
            'maintenance' => MaintenanceTask::query()
                ->where('sensor_station_id', $station->id)
                ->orderByDesc('scheduled_for')
                ->limit(4)
                ->get()
                ->map(fn (MaintenanceTask $task) => [
                    'title' => $task->title,
                    'type' => $task->type,
                    'status' => $task->status,
                    'scheduled_for' => $task->scheduled_for->format('d M Y'),
                    'assignee' => $task->assignee,
                ])->all(),
        ];
    }

    /** Store a new marker position (percentages of the photo region). */
    public function moveStation(string $code, float $x, float $y): array
    {
        /** @var SensorStation $station */
        $station = SensorStation::query()
            ->where('dam_id', $this->dam()->id)
            ->where('code', $code)
            ->firstOrFail();

        $station->forceFill([
            'map_x' => round(max(0, min(100, $x)), 3),
            'map_y' => round(max(0, min(100, $y)), 3),
        ])->save();

        return [
            'code' => $station->code,
            'x' => (float) $station->map_x,
            'y' => (float) $station->map_y,
        ];
    }

    /** Store where a station sits inside the base panorama (degrees). */
    public function moveStationSphere(string $code, float $yaw, float $pitch): array
    {
        /** @var SensorStation $station */
        $station = SensorStation::query()
            ->where('dam_id', $this->dam()->id)
            ->where('code', $code)
            ->firstOrFail();

        $yaw = fmod($yaw, 360);

        if ($yaw > 180) {
            $yaw -= 360;
        } elseif ($yaw < -180) {
            $yaw += 360;
        }

        $station->forceFill([
            'sphere_yaw' => round($yaw, 3),
            'sphere_pitch' => round(max(-85, min(85, $pitch)), 3),
        ])->save();

        return [
            'code' => $station->code,
            'sphere' => $this->spherePosition($station, $this->sphereOrigin()),
        ];
    }

    /** The station whose panorama the stage opens on. */
    private function sphereOrigin(): ?SensorStation
    {
        return once(fn () => SensorStation::query()
            ->where('dam_id', $this->dam()->id)
            ->where('code', config('dam.stage.base_station', 'base-dam'))
            ->first());
    }

    /**
     * Angles for one pin inside the base panorama.
     *
     * Stored angles win. Otherwise the plan-view map percentages give a
     * bearing from the panorama's own position (x grows east, y grows south),
     * and the distance decides how far below the horizon the pin hangs: things
     * close to the camera sit low in the frame, far ones near eye level.
     */
    private function spherePosition(SensorStation $station, ?SensorStation $origin): array
    {
        if ($station->sphere_yaw !== null && $station->sphere_pitch !== null) {
            return [
                'yaw' => (float) $station->sphere_yaw,
                'pitch' => (float) $station->sphere_pitch,
                'placed' => true,
            ];
        }

        $config = config('dam.stage.sphere');

        if (! $origin || $origin->is($station)) {
            return ['yaw' => 0.0, 'pitch' => (float) $config['pitch_far'], 'placed' => false];
        }

        $east = (float) $station->map_x - (float) $origin->map_x;
        $south = (float) $station->map_y - (float) $origin->map_y;
        $distance = sqrt($east ** 2 + $south ** 2);

        $yaw = rad2deg(atan2($east, -$south)) + (float) $config['yaw_offset'];
        $near = (float) $config['pitch_near'];
        $far = (float) $config['pitch_far'];
        $pitch = $far + ($near - $far) * exp(-$distance / max(0.5, (float) $config['falloff']));

        return [
            'yaw' => round(fmod($yaw + 540, 360) - 180, 3),
            'pitch' => round($pitch, 3),
            'placed' => false,
        ];
    }

    public function series(string $code, string $metricKey, string $range = '24h'): array
    {
        /** @var SensorStation $station */
        $station = SensorStation::query()
            ->where('dam_id', $this->dam()->id)
            ->where('code', $code)
            ->firstOrFail();

        [$from, $to] = $this->rangeBounds($range);
        $metric = $station->metrics()->where('key', $metricKey)->firstOrFail();

        return [
            'station' => $station->code,
            'metric' => [
                'key' => $metric->key,
                'label' => $metric->label,
                'unit' => $metric->unit,
                'chart_type' => $metric->chart_type,
                'normal_min' => $metric->normal_min,
                'normal_max' => $metric->normal_max,
                'warning' => $metric->warning_threshold,
            ],
            'range' => $range,
            'points' => $this->telemetry->series($station, $metricKey, $from, $to, 400),
        ];
    }

    /* ------------------------------------------------------------------ *
     |  Building blocks
     * ------------------------------------------------------------------ */

    private function primaryParameters(Collection $stations, array $latest): array
    {
        $tiles = [];

        foreach ((array) config('dam.primary_parameters') as $definition) {
            $station = $stations->firstWhere('code', $definition['station']);
            if (! $station) {
                continue;
            }

            $metric = $station->metrics->firstWhere('key', $definition['metric']);
            if (! $metric) {
                continue;
            }

            $reading = $latest[$station->id][$metric->key] ?? null;
            $value = $reading['value'] ?? null;
            $recordedAt = $reading['recorded_at'] ?? null;
            $trend = ($definition['trend'] ?? false)
                ? $this->trend($value, $this->previousValue($station, $metric->key, $recordedAt), $metric)
                : null;

            $tiles[] = [
                'station' => $station->code,
                'metric' => $metric->key,
                'label' => $definition['label'] ?? $metric->label,
                'value' => $value,
                'formatted' => $value === null ? '—' : $this->formatValue($value, $metric, false),
                'unit' => $metric->unit,
                'status' => $metric->statusFor($value === null ? null : (float) $value),
                'trend' => $trend,
                'note' => $definition['note'] ?? null,
                'recorded_at' => $recordedAt?->toIso8601String(),
                'recorded_label' => $recordedAt ? $this->localTime($recordedAt, true) : null,
            ];
        }

        return $tiles;
    }

    private function recentReadings(Collection $stations, array $latest): array
    {
        $rows = [];

        foreach ((array) config('dam.recent_readings') as $definition) {
            $station = $stations->firstWhere('code', $definition['station']);
            if (! $station) {
                continue;
            }

            $metric = $station->metrics->firstWhere('key', $definition['metric']);
            if (! $metric) {
                continue;
            }

            $reading = $latest[$station->id][$metric->key] ?? null;
            $value = $reading['value'] ?? null;
            $recordedAt = $reading['recorded_at'] ?? null;
            $status = $metric->statusFor($value === null ? null : (float) $value);

            $rows[] = [
                'station' => $station->code,
                'metric' => $metric->key,
                'icon' => $definition['icon'] ?? 'sensor',
                'label' => $definition['label'] ?? $metric->label,
                'value' => $value,
                'formatted' => $value === null ? '—' : $this->formatValue($value, $metric, false),
                'unit' => $metric->unit,
                'status' => $status,
                'status_label' => config("dam.statuses.{$status}.label", 'Normal'),
                'show_status_text' => in_array($metric->key, ['pore_pressure', 'piezo_level'], true),
                'recorded_label' => $recordedAt ? $this->localTime($recordedAt, true) : '—',
            ];
        }

        return $rows;
    }

    /** Status counts per bucket across every evaluated metric. */
    private function evaluate(Collection $stations, array $latest): array
    {
        $evaluations = [];

        foreach ($stations as $station) {
            $readings = $latest[$station->id] ?? [];

            foreach ($station->metrics as $metric) {
                $value = $readings[$metric->key]['value'] ?? null;
                $evaluations[] = [
                    'station' => $station,
                    'metric' => $metric,
                    'status' => $station->is_online
                        ? $metric->statusFor($value === null ? null : (float) $value)
                        : 'offline',
                ];
            }
        }

        return $evaluations;
    }

    private function health(array $evaluations): array
    {
        $total = max(1, count($evaluations));
        $buckets = ['aman' => 0, 'waspada' => 0, 'siaga' => 0, 'bahaya' => 0];

        foreach ($evaluations as $evaluation) {
            $bucket = config("dam.statuses.{$evaluation['status']}.bucket", 'aman');
            $buckets[$bucket] = ($buckets[$bucket] ?? 0) + 1;
        }

        $percent = [];
        foreach ($buckets as $key => $count) {
            $percent[$key] = (int) round($count / $total * 100);
        }

        // Rounding can drift by a point or two; absorb it into the largest bucket.
        $drift = 100 - array_sum($percent);
        if ($drift !== 0) {
            $largest = array_search(max($percent), $percent, true);
            $percent[$largest] += $drift;
        }

        return [
            'score' => $percent['aman'],
            'total_points' => $total,
            'buckets' => collect($percent)->map(fn (int $value, string $key) => [
                'key' => $key,
                'label' => ucfirst($key),
                'percent' => $value,
                'count' => $buckets[$key],
                'color' => match ($key) {
                    'aman' => '#34d399',
                    'waspada' => '#fbbf24',
                    'siaga' => '#fb923c',
                    default => '#f87171',
                },
            ])->values()->all(),
        ];
    }

    private function activeAlerts(int $limit = 6): array
    {
        return Alert::query()
            ->where('dam_id', $this->dam()->id)
            ->active()
            ->with('station:id,code,name')
            ->orderByRaw("CASE level WHEN 'bahaya' THEN 0 WHEN 'siaga' THEN 1 ELSE 2 END")
            ->orderByDesc('triggered_at')
            ->limit($limit)
            ->get()
            ->map(fn (Alert $alert) => $this->presentAlert($alert))
            ->all();
    }

    private function presentAlert(Alert $alert): array
    {
        // Keep the converted instance: the cast returns an immutable Carbon,
        // so setTimezone() does not change the model's own attribute.
        $localised = $alert->triggered_at->setTimezone($this->dam()->timezone);

        return [
            'id' => $alert->id,
            'level' => $alert->level,
            'level_label' => config("dam.statuses.{$alert->level}.label", ucfirst($alert->level)),
            'category' => $alert->category,
            'title' => $alert->title,
            'message' => $alert->message,
            'station' => $alert->station?->only(['code', 'name']),
            'triggered_at' => $alert->triggered_at->toIso8601String(),
            'triggered_label' => $localised->format('d/m H:i').' '.$this->zoneLabel($localised),
            'relative' => $alert->triggered_at->diffForHumans(),
            'is_resolved' => $alert->resolved_at !== null,
        ];
    }

    private function systemStatus(Collection $stations, array $evaluations): array
    {
        $online = $stations->where('is_online', true)->count();

        return [
            'network' => $online === $stations->count() ? 'online' : 'degraded',
            'server' => 'online',
            'database' => 'online',
            'stations_total' => $stations->count(),
            'stations_online' => $online,
            'metrics_tracked' => count($evaluations),
            'backup_at' => CarbonImmutable::now($this->dam()->timezone)->subDay()->setTime(23, 0)->format('d/m H:i'),
        ];
    }

    private function stationStatus(SensorStation $station, array $readings): string
    {
        if (! $station->is_online) {
            return 'offline';
        }

        $worst = 'normal';

        foreach ($station->metrics as $metric) {
            $value = $readings[$metric->key]['value'] ?? null;
            $status = $metric->statusFor($value === null ? null : (float) $value);

            if (SensorStation::STATUS_WEIGHT[$status] > SensorStation::STATUS_WEIGHT[$worst]) {
                $worst = $status;
            }
        }

        return $worst;
    }

    private function previousValue(SensorStation $station, string $metricKey, ?CarbonInterface $before): ?float
    {
        if ($before === null) {
            return null;
        }

        $reading = SensorReading::query()
            ->where('sensor_station_id', $station->id)
            ->where('metric_key', $metricKey)
            ->where('recorded_at', '<', $before)
            ->orderByDesc('recorded_at')
            ->first(['value']);

        return $reading ? (float) $reading->value : null;
    }

    private function trend(?float $value, ?float $previous, SensorMetric $metric): ?array
    {
        if ($value === null || $previous === null) {
            return null;
        }

        $delta = round($value - $previous, max(3, $metric->decimals));

        return [
            'delta' => $delta,
            'direction' => $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat'),
            'formatted' => sprintf('%s%s %s', $delta > 0 ? '+' : ($delta < 0 ? '' : ''), rtrim(rtrim(number_format($delta, max(2, $metric->decimals), ',', '.'), '0'), ','), $metric->unit),
        ];
    }

    /** Clock time at the dam, optionally with its timezone abbreviation. */
    private function localTime(CarbonInterface $moment, bool $withZone = false): string
    {
        $localised = $moment->setTimezone($this->dam()->timezone);

        return $withZone
            ? $localised->format('H:i').' '.$this->zoneLabel($localised)
            : $localised->format('H:i');
    }

    private function formatValue(float $value, SensorMetric $metric, bool $withUnit = true): string
    {
        $formatted = number_format($value, $metric->decimals, ',', '.');

        return $withUnit && $metric->unit ? "{$formatted} {$metric->unit}" : $formatted;
    }

    private function rangeBounds(string $range): array
    {
        $to = CarbonImmutable::now();

        $from = match ($range) {
            '6h' => $to->subHours(6),
            '7d' => $to->subDays(7),
            '30d' => $to->subDays(30),
            default => $to->subDay(),
        };

        return [$from, $to];
    }

    private function typeLabel(string $type): string
    {
        return [
            'water_level' => 'Muka Air / AWLR',
            'water_quality' => 'Kualitas Air',
            'sediment' => 'Sedimen',
            'piezometer' => 'Piezometer',
            'observation_well' => 'Observation Well',
            'seepage' => 'Rembesan',
            'deformation' => 'Deformasi',
            'weather' => 'Stasiun Cuaca',
            'rainfall' => 'Curah Hujan',
            'gate' => 'Pintu Air',
            'cctv' => 'CCTV',
            'ews' => 'Early Warning System',
            'network' => 'Perangkat Jaringan',
            'overview' => 'Ikhtisar Bendungan',
        ][$type] ?? ucfirst(str_replace('_', ' ', $type));
    }

    private function formatDate(CarbonInterface $moment): string
    {
        $days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sept', 'Okt', 'Nov', 'Des'];

        return sprintf(
            '%s, %02d %s %d',
            $days[(int) $moment->format('w')],
            $moment->day,
            $months[$moment->month - 1],
            $moment->year,
        );
    }

    /** WIB / WITA / WIT depending on the site's UTC offset. */
    private function zoneLabel(CarbonInterface $moment): string
    {
        return match ((int) round($moment->utcOffset() / 60)) {
            7 => 'WIB',
            8 => 'WITA',
            9 => 'WIT',
            default => $moment->format('T'),
        };
    }
}
