<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\Dam;
use App\Models\GateCommand;
use App\Models\MaintenanceMessage;
use App\Models\MaintenanceTask;
use App\Models\PanoramaHotspot;
use App\Models\SensorMetric;
use App\Models\SensorReading;
use App\Models\SensorStation;
use App\Models\Setting;
use App\Models\User;
use App\Services\Telemetry\ReadingSimulator;
use App\Services\Telemetry\TelemetryProvider;
use App\Services\Weather\WeatherProvider;
use App\Support\DashboardLayout;
use App\Support\SkyState;
use App\Support\SolarClock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

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

    /**
     * The parameters a station pin walks through, headline first.
     *
     * A state parameter reads as its word rather than its number — the number
     * is the storage, the word is the reading — and a parameter with nothing
     * behind it still appears, because a gap in the data is something an
     * operator should see rather than a row that quietly vanishes.
     *
     * @param  array<string, array<string, mixed>>  $readings
     * @return list<array<string, mixed>>
     */
    private function pinReadings(SensorStation $station, array $readings, ?SensorMetric $primary): array
    {
        return $station->metrics
            ->sortByDesc(fn (SensorMetric $metric) => $metric->key === $primary?->key)
            ->map(function (SensorMetric $metric) use ($readings) {
                $value = $readings[$metric->key]['value'] ?? null;
                $value = $value === null ? null : (float) $value;

                return [
                    'key' => $metric->key,
                    'label' => $metric->label,
                    'value' => $value === null
                        ? '—'
                        : ($metric->stateLabel($value) ?? $this->formatValue($value, $metric)),
                    'status' => $value === null ? 'offline' : $metric->statusFor($value),
                ];
            })
            ->values()
            ->all();
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
            'sky' => $this->sky($sun['elevation']),
            'stage' => $this->stage(),
        ];
    }

    /**
     * The sky as the instruments see it: light against rain.
     *
     * Cloud has no sensor, but it has a shadow — illuminance far below what a
     * clear sky would deliver at this solar elevation. Read with the rain
     * gauge it tells an overcast morning apart from one that is already
     * drizzling, which is what the stage illustrates.
     */
    public function sky(float $elevation): array
    {
        $station = SensorStation::query()
            ->where('dam_id', $this->dam()->id)
            ->where('type', 'weather')
            ->with('metrics')
            ->first();

        $latest = $station
            ? ($this->telemetry->latestForStations(collect([$station]))[$station->id] ?? [])
            : [];

        $value = fn (string $key) => isset($latest[$key]) ? (float) $latest[$key]['value'] : null;

        $sky = app(SkyState::class);

        return $sky->read(
            $value('illuminance'),
            $elevation,
            $value('rainfall_intensity'),
            $value('rainfall_24h'),
        ) + [
            // The what-if buttons read their numbers from here, so the browser
            // never invents a scene the server would not have produced.
            'presets' => collect(array_keys($sky->labels()))
                ->mapWithKeys(fn (string $code) => [$code => $sky->scenario($code)])
                ->all(),
        ];
    }

    /** A what-if preset, for the stage's scenario buttons. */
    public function skyScenario(string $code): array
    {
        return app(SkyState::class)->scenario($code);
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
                    'north_offset' => $this->northOffset($base),
                    'bearing' => $base->panorama_bearing,
                ],
                'phases' => $this->basePhases($base),
                'sections' => $base ? $this->sections($base) : [],
            ] : null,
            'default_zoom' => (int) config('dam.stage.default_zoom', 45),
            'drift_arc' => (float) config('dam.stage.drift_arc', 55),
            'default_bearing' => (float) config('dam.stage.default_bearing', 0),
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
            'alert_history' => $this->alertHistory(),
            'system' => $this->systemStatus($stations, $evaluated),
        ];
    }

    /**
     * Textures for the base panorama: one per time of day, plus the weather.
     *
     * The four solar ones are rendered from the same viewpoint
     * (`Transitions/Base Dam`) and built by `tools/build_panorama_phases.py`,
     * so the stage can cross-fade between them. A phase without a file falls
     * back to the daylight sphere, so a half-built asset folder still works.
     *
     * The weather ones (`tools/build_panorama_weather.py`) are the exception
     * to that fallback: they are *omitted* when the file is missing rather
     * than aliased to daylight, because the stage has to be able to tell that
     * there is no overcast render and keep painting cloud over the screen
     * instead. A daylight sphere returned under the name `mendung` would be a
     * clear sky the stage believed was covered.
     *
     * @return array<string, array{url: string, preview: string}>
     */
    private function basePhases(SensorStation $base): array
    {
        $day = ['url' => $base->panoramaUrl(), 'preview' => $base->panoramaPreviewUrl()];

        $texture = fn (string $name) => [
            'url' => asset("assets/panorama/{$base->panorama}-{$name}.webp"),
            'preview' => asset("assets/panorama/preview/{$base->panorama}-{$name}.webp"),
        ];

        $has = fn (string $name) => file_exists(
            public_path("assets/panorama/{$base->panorama}-{$name}.webp")
        );

        $phases = collect(config('dam.map.phases'))
            ->mapWithKeys(fn (string $phase) => [$phase => $has($phase) ? $texture($phase) : $day]);

        return $phases
            ->merge(
                collect(config('dam.stage.weather', []))
                    ->filter($has)
                    ->mapWithKeys(fn (string $sky) => [$sky => $texture($sky)])
            )
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
                    'north_offset' => $this->northOffset($station),
                    // Which way that panorama opens, measured from north.
                    'bearing' => $station->panorama_bearing,
                ] : null,
                'primary_metric' => $primary?->key,
                'caption' => $primary && $value !== null
                    ? $this->formatValue($value, $primary)
                    : ($station->is_online ? 'Aktif' : 'Offline'),
                /*
                | Every parameter the pin can show, headline first. A pin that
                | names one figure and hides the other nine is a pin the reader
                | has to open the station to get past; the caption walks
                | through these instead, one at a time, and says which it is
                | showing. Already-formatted, because formatting a reading is
                | the service's job wherever else it happens.
                */
                'readings' => $this->pinReadings($station, $readings, $primary),
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
                'north_offset' => $this->northOffset($station),
                'bearing' => $station->panorama_bearing,
            ],
            'hotspots' => $station->hotspots->map(fn (PanoramaHotspot $hotspot) => [
                'id' => $hotspot->id,
                'type' => $hotspot->type,
                'label' => $hotspot->label,
                'description' => $hotspot->description,
                'metric_key' => $hotspot->metric_key,
                'yaw' => $hotspot->yaw,
                'pitch' => $hotspot->pitch,
                'meta' => $hotspot->meta ?? [],
                'stakes' => $this->stakes($hotspot, $station, $readings),
                'section' => $hotspot->isSection() ? $this->piezoSection($hotspot) : null,
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

    /* ------------------------------------------------------------------ *
     |  Maintenance desk
     * ------------------------------------------------------------------ */

    /**
     * Every job with its conversation, newest activity first.
     *
     * One list serves both sides of the desk: the control room sees what it
     * asked for and what came back, the service desk sees the queue.
     */
    public function maintenanceTickets(string $scope = 'aktif', string $side = 'operator'): array
    {
        $tasks = MaintenanceTask::query()
            ->where('dam_id', $this->dam()->id)
            ->when($scope === 'aktif', fn ($query) => $query->where('status', '!=', 'selesai'))
            ->when($scope === 'riwayat', fn ($query) => $query->where('status', 'selesai'))
            ->with(['station:id,code,name,short_name', 'messages.author:id,name,role', 'requester:id,name'])
            ->orderByRaw('COALESCE(last_message_at, updated_at) desc')
            ->get();

        return $tasks->map(fn (MaintenanceTask $task) => $this->ticketPayload($task, $side))->all();
    }

    /** Messages the other side has not opened yet, per role. */
    public function maintenanceUnread(string $role = 'operator'): int
    {
        $from = $this->otherSide($role);

        return MaintenanceMessage::query()
            ->whereNull('read_at')
            ->where('author_role', $from)
            ->whereHas('task', fn ($query) => $query->where('dam_id', $this->dam()->id))
            ->count();
    }

    /** Open a job from a request made in the control room. */
    public function openMaintenanceRequest(User $user, array $data): array
    {
        $station = isset($data['station'])
            ? SensorStation::query()->where('dam_id', $this->dam()->id)->where('code', $data['station'])->first()
            : null;

        $task = MaintenanceTask::query()->create([
            'dam_id' => $this->dam()->id,
            'sensor_station_id' => $station?->id,
            'title' => $data['title'],
            'type' => $data['type'] ?? 'korektif',
            'source' => 'permintaan',
            'status' => 'terjadwal',
            'priority' => $data['priority'] ?? 'normal',
            'requested_by' => $user->id,
            'scheduled_for' => $data['scheduled_for'] ?? now($this->dam()->timezone)->toDateString(),
            'notes' => $data['body'] ?? null,
        ]);

        if (! empty($data['body'])) {
            $this->postMaintenanceMessage($task, $user, $data['body']);

            return $this->ticketPayload($task->fresh(['station', 'messages.author', 'requester']), $user->deskSide());
        }

        return $this->ticketPayload($task->fresh(['station', 'messages.author', 'requester']), $user->deskSide());
    }

    /** Add a line to the conversation; the author's role decides the side. */
    public function postMaintenanceMessage(MaintenanceTask $task, User $user, string $body): array
    {
        $role = $user->deskSide();

        $message = $task->messages()->create([
            'user_id' => $user->id,
            'author_name' => $user->name,
            'author_role' => $role,
            'body' => $body,
        ]);

        $task->forceFill(['last_message_at' => $message->created_at])->save();

        return $this->messagePayload($message->fresh('author'));
    }

    /** Mark what the other side wrote as seen. */
    public function readMaintenanceThread(MaintenanceTask $task, User $user): int
    {
        $from = $this->otherSide($user->deskSide());

        return $task->messages()
            ->whereNull('read_at')
            ->where('author_role', $from)
            ->update(['read_at' => now()]);
    }

    /**
     * Everything already done, per asset.
     *
     * This is the log an auditor asks for: which instrument, what was done,
     * when it finished and who did it.
     */
    public function maintenanceHistory(?string $stationCode = null, int $limit = 60): array
    {
        return MaintenanceTask::query()
            ->where('dam_id', $this->dam()->id)
            ->where('status', 'selesai')
            ->when($stationCode, fn ($query) => $query->whereHas(
                'station',
                fn ($inner) => $inner->where('code', $stationCode)
            ))
            ->with(['station:id,code,name,short_name'])
            ->withCount('messages')
            ->orderByDesc('completed_at')
            ->limit($limit)
            ->get()
            ->map(fn (MaintenanceTask $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'type' => $task->type,
                'type_label' => $this->maintenanceTypeLabel($task->type),
                'station' => $task->station?->short_name ?? $task->station?->name,
                'station_code' => $task->station?->code,
                'assignee' => $task->assignee,
                'notes' => $task->notes,
                'messages' => $task->messages_count,
                'completed_at' => $this->stamp($task->completed_at),
                'scheduled_for' => $task->scheduled_for?->format('d M Y'),
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    private function ticketPayload(MaintenanceTask $task, string $side = 'operator'): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status,
            'priority' => $task->priority,
            'type' => $task->type,
            'type_label' => $this->maintenanceTypeLabel($task->type),
            'source' => $task->source,
            'assignee' => $task->assignee,
            'requester' => $task->requester?->name,
            'requester_id' => $task->requested_by,
            'station' => $task->station?->short_name ?? $task->station?->name,
            'station_code' => $task->station?->code,
            'scheduled_for' => $task->scheduled_for?->format('d M Y'),
            'completed_at' => $this->stamp($task->completed_at),
            'unread' => $task->messages->whereNull('read_at')->where('author_role', $this->otherSide($side))->count(),
            'messages' => $task->messages->map(fn (MaintenanceMessage $message) => $this->messagePayload($message))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function messagePayload(MaintenanceMessage $message): array
    {
        $at = $message->created_at?->copy()->setTimezone($this->dam()->timezone);

        return [
            'id' => $message->id,
            'body' => $message->body,
            'role' => $message->author_role,
            // The reader compares this with their own id: alignment follows the
            // person, the colour follows the side of the desk.
            'user_id' => $message->user_id,
            'author' => $message->author_name,
            'at' => $at?->format('d M Y H:i'),
            // Split out so the thread can group by day and print the clock
            // alone under a burst of messages.
            'day' => $at?->translatedFormat('d M Y'),
            'clock' => $at?->format('H:i'),
            'relative' => $at?->diffForHumans(),
            'read' => $message->read_at !== null,
        ];
    }

    /** A moment in the dam's own clock, spelled out for a log line. */
    private function stamp(?CarbonInterface $moment): ?string
    {
        return $moment?->copy()->setTimezone($this->dam()->timezone)->format('d M Y H:i');
    }

    private function maintenanceTypeLabel(string $type): string
    {
        return [
            'preventif' => 'Preventif',
            'korektif' => 'Korektif',
            'kalibrasi' => 'Kalibrasi',
            'inspeksi' => 'Inspeksi',
        ][$type] ?? ucfirst($type);
    }

    /** The desk has two sides; this is the one a reader is not on. */
    public function otherSide(string $side): string
    {
        return $side === 'operator' ? 'cs' : 'operator';
    }

    /**
     * Shared bootstrap payload handed to Alpine on every page.
     *
     * The unread badge counts from the reader's own side of the desk, so the
     * service desk is not shown the control room's tally.
     */
    public function bootPayload(?User $user = null): array
    {
        return [
            'environment' => $this->environment(),
            'markers' => $this->markers(),
            'map' => config('dam.map'),
            'refresh' => config('dam.refresh'),
            'statuses' => config('dam.statuses'),
            'skin' => Setting::get('map_skin', 'auto'),
            'maintenance_unread' => $this->maintenanceUnread($user?->deskSide() ?? 'operator'),
            'can' => $user ? array_values($user->permissions()) : [],
        ];
    }

    /**
     * How far each prism of a monitoring plot has moved, and which way.
     *
     * The instrument measures the dam body, not one stake: `displacement_h`
     * and `displacement_v` are what the RTS reports for this station. A plot's
     * prisms are where that movement is *distributed* — the shape of the
     * deformation field across one elevation — so each stake carries a fixed
     * share of the measured figure, derived from its own code so the pattern
     * is stable between requests instead of shimmering every poll.
     *
     * `aspect` is an angle **in the picture**, not a surveyed azimuth: it
     * comes from the plot's own aspect — which way the body is pushed in that
     * panorama, away from the water — with a small per-stake deviation, and it
     * is what the arrow on the stage is drawn at. Nothing here claims to be a
     * bearing.
     *
     * @return list<array<string, mixed>>
     */
    private function stakes(PanoramaHotspot $hotspot, SensorStation $station, array $readings): array
    {
        if (! $hotspot->isPlot()) {
            return [];
        }

        $meta = $hotspot->meta ?? [];
        $horizontal = $readings['displacement_h']['value'] ?? null;
        $vertical = $readings['displacement_v']['value'] ?? null;
        $metric = $station->metrics->firstWhere('key', 'displacement_h');
        // Downstream, which is where a dam body goes: away from the water.
        $aspect = (float) ($meta['aspect'] ?? 0);

        // A stable number per stake, in 0..1, from its code.
        $share = fn (string $code, string $salt) => (crc32($code.$salt) % 1000) / 1000;

        return array_map(function (string $code) use ($horizontal, $vertical, $metric, $aspect, $share) {
            /*
            | Nothing measured yet, so nothing to judge. Not `offline`: that is
            | a state a logger can be in, and a prism is a piece of glass on a
            | stake — it is the total station that goes off the air. A null
            | status is drawn plain, which says "not read" rather than "read
            | and fine".
            */
            if ($horizontal === null && $vertical === null) {
                return ['code' => $code, 'linear' => null, 'status' => null];
            }

            $sideways = (float) $horizontal * (0.55 + 0.85 * $share($code, 'h'));
            $settling = (float) $vertical * (0.6 + 0.75 * $share($code, 'v'));
            $travelled = sqrt($sideways ** 2 + $settling ** 2);

            /*
            | What the instrument reports at a prism is how far that prism has
            | moved since it was set, not how far it moved this cycle — so the
            | figure carries years of accumulated creep, and the prisms do not
            | carry the same amount. A dam creeps unevenly: most of a face sits
            | well inside its band while a handful of points have gone much
            | further, which is the entire reason anybody watches thirty of
            | them instead of one.
            |
            | Cubed, so that shape comes out: many small, a few large, one or
            | two past the alert. Derived from the code alone, like the share
            | above — from the clock or `rand()` and the field would shimmer on
            | every poll.
            */
            $creep = 0.3 + 26.0 * $share($code, 'creep') ** 3;
            $linear = $travelled + $creep;

            // Keep the parts adding up to the whole the panel prints.
            $grown = $travelled > 1e-6 ? $linear / $travelled : 0.0;
            $sideways *= $grown;
            $settling *= $grown;

            return [
                'code' => $code,
                'linear' => round($linear, 2),
                // Formatting stays on the server, like every other reading.
                'formatted' => number_format($linear, 2, ',', '.'),
                'short' => number_format($linear, 1, ',', '.'),
                'horizontal' => round($sideways, 2),
                'vertical' => round($settling, 2),
                'horizontal_formatted' => number_format($sideways, 2, ',', '.'),
                'vertical_formatted' => number_format($settling, 2, ',', '.'),
                // Plus or minus 10 degrees off the plot's own aspect: prisms
                // on one elevation do not all creep in exactly one direction,
                // but the field still has to read as one direction.
                'aspect' => round($aspect + ($share($code, 'a') * 20) - 10, 1),
                'status' => $metric ? $metric->statusFor($linear) : 'normal',
            ];
        }, $hotspot->stakeCodes());
    }

    /**
     * The sections of piezometers standing on one panorama.
     *
     * They ride the stage payload rather than a station fetch because the one
     * that matters stands on the *base* panorama — the reader has to be able
     * to open the section from the picture of the dam, without visiting a
     * station first. Refreshing with the environment poll is what keeps the
     * phreatic line in the drawing current.
     *
     * @return list<array<string, mixed>>
     */
    private function sections(SensorStation $base): array
    {
        return $base->hotspots()
            ->where('type', 'piezo')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (PanoramaHotspot $hotspot) => $this->piezoSection($hotspot))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * One section of vibrating-wire piezometers, read against the water
     * standing over each of them.
     *
     * A point's figure is **not** a hash of its code, and must never become
     * one. One phreatic surface is stood over the section — the source
     * station's own `piezo_level`, falling `gradient` metres of head per metre
     * downstream — and every instrument takes the water above it: deeper reads
     * higher, upstream higher than downstream, and a point above the surface
     * reads nothing at all. `stakes()` hashes because nothing in a total
     * station's reading says which prism moved; here there is real physics,
     * and hashing it away would be inventing over the top of an answer.
     *
     * Only two things are per point and deterministic from the code: a couple
     * of per cent of sensor scatter, and a cubed local rise of the surface, so
     * a handful of points sit in a wetter path than their neighbours. The rise
     * is on the *surface*, not on the head, so a point standing well clear of
     * the water cannot be made wet by it.
     *
     * @return array<string, mixed>
     */
    public function piezoSection(PanoramaHotspot $hotspot): array
    {
        $meta = $hotspot->meta ?? [];
        $points = $hotspot->piezoPoints();

        if ($points === []) {
            return [];
        }

        $source = $this->sectionStation($hotspot);
        $level = null;

        if ($source) {
            $readings = $this->telemetry->latest($source);
            $level = $readings['piezo_level']['value'] ?? null;
        }

        $gradient = (float) ($meta['gradient'] ?? 0.16);
        $design = (float) ($meta['design_phreatic'] ?? 0);

        // A stable number per instrument, in 0..1, from its code alone.
        $share = fn (string $code, string $salt) => (crc32($code.$salt) % 1000) / 1000;

        $read = array_map(function (array $point) use ($level, $gradient, $design, $share) {
            $code = (string) $point['code'];
            $elevation = (float) $point['elevation'];
            $offset = (float) $point['offset'];

            $row = [
                'code' => $code,
                'kind' => $point['kind'] ?? 'timbunan',
                'label' => $point['label'] ?? null,
                'elevation' => round($elevation, 2),
                'offset' => round($offset, 2),
            ];

            if ($level === null) {
                // Nothing measured yet is not the same as nothing there.
                return $row + ['head' => null, 'pressure' => null, 'level' => null,
                    'design_head' => null, 'design_level' => null, 'freeboard' => null,
                    'dry' => false, 'status' => null];
            }

            // The surface over this point, plus its own wetter path.
            $surface = $level - $gradient * $offset + 3.0 * $share($code, 'path') ** 3;
            $head = ($surface - $elevation) * (1 + ($share($code, 'scatter') - 0.5) * 0.05);
            $dry = $head <= 0;

            /*
            | The band is **freeboard**, in metres: how far the piezometric
            | level stands below the design line over that same point. Never
            | one kPa threshold across the section — an instrument near the
            | crest and one under the foundation cannot be judged by the same
            | number — and never the head against the design head either,
            | which was the first shape and the wrong one: both grow with
            | depth, so their ratio comes out near 1 for every deep instrument
            | and the section reads amber for the crime of being tall. What an
            | engineer watches is the surface climbing towards the line it may
            | not cross, and that is a distance in metres wherever you stand.
            */
            $designLevel = $design - $gradient * $offset;
            $freeboard = $designLevel - ($elevation + $head);

            return $row + [
                'head' => $dry ? null : round($head, 2),
                'pressure' => $dry ? null : round($head * 9.80665, 1),
                'level' => $dry ? null : round($elevation + $head, 2),
                'design_head' => round(max(0.0, $designLevel - $elevation), 2),
                'design_level' => round($designLevel, 2),
                'freeboard' => $dry ? null : round($freeboard, 2),
                'dry' => $dry,
                'status' => $dry ? null : match (true) {
                    $freeboard < 0.0 => 'bahaya',
                    $freeboard < 1.0 => 'siaga',
                    $freeboard < 2.0 => 'waspada',
                    default => 'normal',
                },
            ];
        }, $points);

        $order = ['normal' => 0, 'waspada' => 1, 'siaga' => 2, 'bahaya' => 3];
        $worst = 'normal';

        foreach ($read as $point) {
            if (($order[$point['status'] ?? 'normal'] ?? 0) > $order[$worst]) {
                $worst = $point['status'];
            }
        }

        return [
            'id' => $hotspot->id,
            'label' => $hotspot->label,
            'description' => $hotspot->description,
            'yaw' => (float) $hotspot->yaw,
            'pitch' => (float) $hotspot->pitch,
            'station' => $source?->only(['code', 'name', 'short_name']),
            'level' => $level === null ? null : round((float) $level, 2),
            'gradient' => $gradient,
            'design_phreatic' => $design,
            // Crest, foundation, slopes and core: what the drawing is made of.
            'geometry' => array_diff_key($meta, array_flip(['points', 'station'])),
            'points' => $read,
            'wet' => count(array_filter($read, fn (array $p) => $p['dry'] === false && $p['head'] !== null)),
            'dry' => count(array_filter($read, fn (array $p) => $p['dry'] === true)),
            'status' => $level === null ? null : $worst,
        ];
    }

    /** The station a section reads its phreatic level from. */
    private function sectionStation(PanoramaHotspot $hotspot): ?SensorStation
    {
        $code = $hotspot->meta['station'] ?? null;

        if (! $code) {
            return $hotspot->station()->with('metrics')->first();
        }

        return SensorStation::query()
            ->where('dam_id', $this->dam()->id)
            ->where('code', $code)
            ->with('metrics')
            ->first();
    }

    /**
     * Order one spillway gate to an opening, in centimetres of its stroke.
     *
     * Centimetres because that is what the hoist reports and what a person
     * says when they open a gate; the percentage beside it is derived, not
     * the other way round.
     *
     * The order is recorded, not applied: what this writes is that somebody
     * asked for this figure at this moment, which is the part a flood report
     * has to be able to quote. The gate then travels there in the readings.
     * Nothing here claims the leaf has moved.
     *
     * @return array<string, mixed>
     */
    public function orderGate(string $code, int $gate, float $opening, ?User $user = null): array
    {
        /** @var SensorStation $station */
        $station = SensorStation::query()
            ->where('dam_id', $this->dam()->id)
            ->where('code', $code)
            ->firstOrFail();

        $leaf = $station->hotspots()
            ->where('type', 'gate')
            ->get()
            ->first(fn (PanoramaHotspot $hotspot) => (int) ($hotspot->meta['gate'] ?? 0) === $gate);

        abort_unless($leaf !== null, 404);

        $stroke = (float) ($leaf->meta['height_cm'] ?? 0);
        $stroke = $stroke > 0 ? $stroke : 100.0;

        /*
        | Refused, not trimmed. Quietly reducing 140 cm to 100 would record an
        | order nobody gave and leave the operator believing the gate is going
        | somewhere it is not — on a control that opens a spillway, silently
        | doing something other than what was asked is the worst answer
        | available.
        */
        if ($opening < 0 || $opening > $stroke) {
            throw ValidationException::withMessages([
                'opening' => "Bukaan harus antara 0 dan {$stroke} cm, yaitu langkah penuh {$leaf->label}.",
            ]);
        }

        $opening = round($opening, 2);

        $command = GateCommand::query()->create([
            'sensor_station_id' => $station->id,
            'gate' => $gate,
            'opening' => $opening,
            'user_id' => $user?->id,
            'issued_at' => CarbonImmutable::now(),
        ]);

        /*
        | Post the order to the series straight away, so the panel shows the
        | target the moment it is given instead of waiting for the next
        | telemetry tick. The opening is written too, at where the leaf still
        | is: the order has been given, the gate has not moved, and the panel
        | should say exactly that.
        */
        $this->postGateReadings($station, $gate);

        return [
            'station' => $station->code,
            'gate' => $gate,
            'label' => $leaf->label,
            'stroke_cm' => $stroke,
            'opening' => $opening,
            'opening_percent' => round($opening / $stroke * 100, 1),
            // Stored in UTC, read in the dam's own hours; the converted
            // instance has to be kept, the models return immutables.
            'issued_at' => $command->issued_at->setTimezone($this->dam()->timezone)->format('d M Y H:i'),
            'by' => $user?->name,
        ];
    }

    /**
     * Write this instant's opening and target for one gate.
     *
     * Through the simulator, so the two rows agree with everything the
     * scheduled run will write after them; when a real logger replaces the
     * simulator this is the one call that goes, and the gate then reports
     * itself.
     */
    private function postGateReadings(SensorStation $station, int $gate): void
    {
        $simulator = app(ReadingSimulator::class);
        $simulator->forgetOrders($station);

        $now = CarbonImmutable::now();

        foreach (["gate_opening_{$gate}", "gate_target_{$gate}"] as $key) {
            $metric = $station->metrics()->where('key', $key)->first();

            if (! $metric) {
                continue;
            }

            SensorReading::query()->create([
                'sensor_station_id' => $station->id,
                'metric_key' => $key,
                'value' => round($simulator->value($station, $metric, $now), 4),
                'quality' => 'good',
                'recorded_at' => $now,
            ]);
        }
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

        $station->forceFill([
            'sphere_yaw' => round($this->normaliseYaw($yaw), 3),
            'sphere_pitch' => round(max(-85, min(85, $pitch)), 3),
        ])->save();

        return [
            'code' => $station->code,
            'sphere' => $this->spherePosition($station, $this->sphereOrigin()),
        ];
    }

    /**
     * Store where a hotspot sits inside its own station panorama (degrees).
     *
     * The renders carry no survey, so the seeded angles for a prism or an
     * instrument are an estimate. Placing one is the same gesture as placing a
     * station pin on the base panorama, and it writes to the same kind of
     * record rather than to a browser preference.
     */
    public function moveHotspot(int $id, float $yaw, float $pitch): array
    {
        /** @var PanoramaHotspot $hotspot */
        $hotspot = PanoramaHotspot::query()
            ->whereKey($id)
            ->whereHas('station', fn ($query) => $query->where('dam_id', $this->dam()->id))
            ->firstOrFail();

        $hotspot->forceFill([
            'yaw' => round($this->normaliseYaw($yaw), 3),
            'pitch' => round(max(-85, min(85, $pitch)), 3),
        ])->save();

        return [
            'id' => $hotspot->id,
            'station' => $hotspot->station->code,
            'label' => $hotspot->label,
            'yaw' => (float) $hotspot->yaw,
            'pitch' => (float) $hotspot->pitch,
        ];
    }

    /**
     * Nudge one stake off the line's own layout.
     *
     * A line of prisms is one record, so a correction to a single stake is
     * stored as an offset in that record's `meta.places` rather than as a row
     * of its own. Offsets, not angles: moving the line later has to carry
     * every correction with it, which an absolute angle would not.
     */
    public function moveStake(int $id, int $stake, float $offsetYaw, float $offsetPitch): array
    {
        /** @var PanoramaHotspot $hotspot */
        $hotspot = PanoramaHotspot::query()
            ->whereKey($id)
            ->whereHas('station', fn ($query) => $query->where('dam_id', $this->dam()->id))
            ->firstOrFail();

        $meta = $hotspot->meta ?? [];
        $count = (int) ($meta['stakes'] ?? 0);

        abort_unless($hotspot->isPlot() && $stake >= 1 && $stake <= $count, 404);

        $meta['places'] = ($meta['places'] ?? []) + [];
        $meta['places'][(string) $stake] = [round($offsetYaw, 3), round($offsetPitch, 3)];

        $hotspot->forceFill(['meta' => $meta])->save();

        return [
            'id' => $hotspot->id,
            'station' => $hotspot->station->code,
            'stake' => $hotspot->stakeCodes()[$stake - 1] ?? (string) $stake,
            'offset' => $meta['places'][(string) $stake],
        ];
    }

    /** Fold a yaw into -180..180, which is how the columns are read back. */
    private function normaliseYaw(float $yaw): float
    {
        $yaw = fmod($yaw, 360);

        if ($yaw > 180) {
            return $yaw - 360;
        }

        return $yaw < -180 ? $yaw + 360 : $yaw;
    }

    /**
     * Where north sits inside a panorama, in degrees.
     *
     * The renders carry no orientation, so the compass needs telling. A station
     * can hold its own `panorama_north_offset`; everything else falls back to
     * the one figure in `dam.stage.north_offset`.
     */
    private function northOffset(SensorStation $station): float
    {
        $own = (float) $station->panorama_north_offset;

        return $own !== 0.0 ? $own : (float) config('dam.stage.north_offset', 0);
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

        // What the board is set to show, which is `dam.primary_parameters`
        // until somebody arranges it otherwise.
        foreach (DashboardLayout::tiles() as $definition) {
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

        /*
        | Whatever is already a headline tile is not repeated here. The two
        | lists were configured independently and overlapped on all four
        | figures, so a quarter of the screen was the same numbers twice —
        | printed once in large type and again in a list beside it.
        */
        $onTiles = collect(DashboardLayout::tiles())
            ->map(fn (array $tile) => $tile['station'].':'.$tile['metric'])
            ->all();

        foreach ((array) config('dam.recent_readings') as $definition) {
            if (in_array($definition['station'].':'.$definition['metric'], $onTiles, true)) {
                continue;
            }

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
            // How many parameters are asking for a look. A count, because the
            // score is a rounded fraction with its drift absorbed into the
            // largest bucket — an honest two-digit number it is not.
            'attention' => $total - $buckets['aman'],
            'total' => $total,
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

    /**
     * The newest alerts, settled or not.
     *
     * Distinct from `activeAlerts()` on purpose: the board and the summary
     * column were both drawing the same live list, so one of the two said
     * nothing the other had not. What a shift wants on arriving is what
     * *happened*, including what has since been closed — the standing ones
     * are already down the right of every page.
     *
     * @return list<array<string, mixed>>
     */
    private function alertHistory(int $limit = 8): array
    {
        return Alert::query()
            ->where('dam_id', $this->dam()->id)
            ->with('station:id,code,name')
            ->orderByDesc('triggered_at')
            ->limit($limit)
            ->get()
            ->map(fn (Alert $alert) => $this->presentAlert($alert))
            ->all();
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
            'deformation' => 'Deformasi / ADR',
            'gnss' => 'GNSS & Tiltmeter',
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
