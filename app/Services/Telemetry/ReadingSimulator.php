<?php

namespace App\Services\Telemetry;

use App\Models\GateCommand;
use App\Models\SensorMetric;
use App\Models\SensorReading;
use App\Models\SensorStation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Generates plausible instrumentation history so the dashboard has something
 * to draw before the real loggers are wired up.
 *
 * Values are a pure function of (metric, timestamp): the same moment always
 * produces the same number, so the seeder and the `telemetry:simulate`
 * command can extend the series without keeping any state, and the curves
 * stay continuous across runs.
 *
 * The spillway gates are the one exception, and they have to be: a gate an
 * operator opened stays open. Their standing orders come from `gate_commands`,
 * are read once per station and cached, and still give the same number for the
 * same moment — the history is reproducible, it just depends on the orders as
 * well as the clock.
 *
 * Replace this with real data by pointing TELEMETRY_DRIVER at the upstream
 * API or by pushing to POST /api/ingest — nothing else has to change.
 */
class ReadingSimulator
{
    /** Rain drives level, inflow, seepage, turbidity and pore pressure alike. */
    private const WET_SEASON_MONTHS = [11, 12, 1, 2, 3, 4];

    /** How long a gate takes to travel its whole stroke, in minutes. */
    private const GATE_TRAVEL = 4.0;

    /** Standing orders per station, loaded once and kept for the run. */
    private array $orders = [];

    /** Each leaf's full stroke in centimetres, from its marker. */
    private array $strokes = [];

    /**
     * Write readings for every metric of a station across a time window.
     *
     * @return int number of rows inserted
     */
    public function fill(
        SensorStation $station,
        CarbonInterface $from,
        CarbonInterface $to,
        int $stepMinutes = 15,
    ): int {
        $metrics = $station->metrics;

        if ($metrics->isEmpty()) {
            return 0;
        }

        $rows = [];
        $inserted = 0;
        $cursor = CarbonImmutable::instance($from)->startOfMinute();
        $end = CarbonImmutable::instance($to);
        $timestamp = now()->toDateTimeString();

        while ($cursor <= $end) {
            foreach ($metrics as $metric) {
                $rows[] = [
                    'sensor_station_id' => $station->id,
                    'metric_key' => $metric->key,
                    'value' => round($this->value($station, $metric, $cursor), 4),
                    'quality' => 'good',
                    'recorded_at' => $cursor->toDateTimeString(),
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }

            if (count($rows) >= 2000) {
                SensorReading::query()->insert($rows);
                $inserted += count($rows);
                $rows = [];
            }

            $cursor = $cursor->addMinutes($stepMinutes);
        }

        if ($rows !== []) {
            SensorReading::query()->insert($rows);
            $inserted += count($rows);
        }

        return $inserted;
    }

    /**
     * Where one gate is, at a moment.
     *
     * With no standing order the gate follows the flood: the wetter the
     * catchment, the further it is opened, each leaf a little differently
     * because three hoists are never in perfect step. Once an operator has
     * given it a figure it travels there over `GATE_TRAVEL` and then holds —
     * which is what a gate under manual control does, and what makes the order
     * visible on the chart as a ramp rather than a jump.
     */
    private function gate(SensorStation $station, int $gate, CarbonImmutable $at, float $wetness): float
    {
        $auto = $this->gateAuto($station, $gate, $at, $wetness);
        $order = $this->order($station, $gate, $at);

        if (! $order) {
            return $auto;
        }

        $travelled = $order->issued_at->diffInSeconds($at) / 60 / self::GATE_TRAVEL;

        $stroke = $this->stroke($station, $gate);

        if ($travelled >= 1.0) {
            return $this->withinStroke($order->opening, $stroke);
        }

        /*
        | The ramp starts from the schedule rather than from a remembered
        | position: over four minutes the schedule has barely moved, so this
        | is where the gate was to within a fraction of a percent, and it
        | needs no state carried between rows.
        */
        return $this->withinStroke($auto + ($order->opening - $auto) * max(0.0, $travelled), $stroke);
    }

    /**
     * One phase of the current a gate's hoist is drawing.
     *
     * Holding current while the leaf is parked, full load while it travels to
     * an order. The three phases are never identical — a motor with perfectly
     * balanced phases is a motor nobody has to inspect — so each is offset a
     * little, and the imbalance is what an operator is actually watching this
     * channel for.
     */
    private function gateCurrent(
        SensorStation $station,
        int $gate,
        CarbonImmutable $at,
        float $wetness,
        int $phase,
    ): float {
        $seed = $station->code.':hoist'.$gate.':'.$phase;
        $jitter = $this->wave($seed, $at, 23);
        $order = $this->order($station, $gate, $at);
        $hauling = $order
            && $order->issued_at->diffInSeconds($at) / 60 < self::GATE_TRAVEL
            && $order->issued_at->lessThanOrEqualTo($at);

        // A little more when the leaf is loaded, which it is when the reservoir
        // is up: the water is pushing on what the hoist has to lift.
        $load = $hauling ? 8.4 + 2.6 * $wetness : 0.32;
        $balance = [1.0, 0.965, 1.028][$phase] ?? 1.0;

        return max(0.0, $load * $balance + ($hauling ? 0.45 : 0.05) * $jitter);
    }

    /** What the controller is asking of a gate: the order, or the flood. */
    private function gateTarget(SensorStation $station, int $gate, CarbonImmutable $at, float $wetness): float
    {
        $order = $this->order($station, $gate, $at);

        return $order
            ? $this->withinStroke($order->opening, $this->stroke($station, $gate))
            : $this->gateAuto($station, $gate, $at, $wetness);
    }

    /** The automatic schedule, before anybody overrode it. */
    private function gateAuto(SensorStation $station, int $gate, CarbonImmutable $at, float $wetness): float
    {
        $seed = $station->code.':gate'.$gate;
        $slow = $this->wave($seed.':slow', $at, 9 * 1440);
        $fast = $this->wave($seed.':fast', $at, 300);

        // The middle leaf leads, the flanking pair follow — the usual way a
        // three-bay spillway is worked so the chute is loaded evenly.
        $lead = [1 => 0.86, 2 => 1.0, 3 => 0.9][$gate] ?? 1.0;
        $stroke = $this->stroke($station, $gate);
        $share = $lead * (0.18 + 0.10 * $slow + 0.45 * $wetness) + 0.015 * $fast;

        return $this->withinStroke($share * $stroke, $stroke);
    }

    /**
     * A leaf's full stroke, in centimetres.
     *
     * The hoist reports how far the leaf is up, not what fraction of itself it
     * has travelled, so every figure here is a length and the stroke is what
     * bounds it. It lives on the gate's own marker (`meta.height_cm`), which
     * is where a site fills in the leaf it actually has.
     */
    private function stroke(SensorStation $station, int $gate): float
    {
        if (! array_key_exists($station->id, $this->strokes)) {
            $this->strokes[$station->id] = $station->hotspots()
                ->where('type', 'gate')
                ->get()
                ->mapWithKeys(fn ($hotspot) => [
                    (int) ($hotspot->meta['gate'] ?? 0) => (float) ($hotspot->meta['height_cm'] ?? 0),
                ])
                ->all();
        }

        $stroke = $this->strokes[$station->id][$gate] ?? 0.0;

        return $stroke > 0 ? $stroke : 100.0;
    }

    /** Keep a length inside the leaf: a gate cannot open past its own stroke. */
    private function withinStroke(float $value, float $stroke): float
    {
        return max(0.0, min($stroke, $value));
    }

    /** The newest order standing over a gate at a moment, if any. */
    private function order(SensorStation $station, int $gate, CarbonImmutable $at): ?GateCommand
    {
        if (! array_key_exists($station->id, $this->orders)) {
            $this->orders[$station->id] = GateCommand::query()
                ->where('sensor_station_id', $station->id)
                ->orderBy('issued_at')
                ->get()
                ->groupBy('gate')
                ->all();
        }

        $standing = null;

        foreach ($this->orders[$station->id][$gate] ?? [] as $order) {
            if ($order->issued_at->greaterThan($at)) {
                break;
            }

            $standing = $order;
        }

        return $standing;
    }

    /** Forget the cached orders, so a fresh command is picked up. */
    public function forgetOrders(?SensorStation $station = null): void
    {
        if ($station) {
            unset($this->orders[$station->id], $this->strokes[$station->id]);

            return;
        }

        $this->orders = [];
        $this->strokes = [];
    }

    public function value(SensorStation $station, SensorMetric $metric, CarbonImmutable $at): float
    {
        $seed = $station->code.':'.$metric->key;
        $diurnal = sin(($at->hour * 60 + $at->minute) / 1440 * M_PI * 2 - M_PI / 2);
        $rainNow = $this->rainIntensity($at);
        $rain24h = $this->rainfall24h($at);
        $wetness = min(1.0, $rain24h / 60);          // 0..1 catchment response
        $slow = $this->wave($seed.':slow', $at, 9 * 1440);
        $fast = $this->wave($seed.':fast', $at, 300);
        $jitter = $this->wave($seed.':jitter', $at, 45) * 0.4 + $this->wave($seed.':jitter2', $at, 17) * 0.2;

        return match ($metric->key) {
            'water_level' => $station->type === 'water_level' && str_contains($station->code, 'hilir')
                ? 27.30 + 0.18 * $slow + 0.5 * $wetness + 0.03 * $jitter
                : 93.70 + 0.32 * $slow + 0.65 * $wetness + 0.012 * $jitter,
            'inflow' => max(1.5, 9.5 + 3.2 * $slow + 34 * $wetness + 1.2 * $jitter),
            'outflow', 'discharge' => max(2.0, 24.0 + 4.5 * $slow + 18 * $wetness + 1.5 * $fast),
            'storage_volume' => 58.0 + 5.5 * $slow + 6 * $wetness,
            /*
            | The three leaves each answer for themselves; the station-wide
            | figure is what the three of them come to together. Their waves
            | are seeded per *gate* rather than per metric — seeded per metric,
            | the average and its three parts were three different noises and
            | the headline disagreed with the leaves under it.
            */
            'gate_opening' => ($this->gate($station, 1, $at, $wetness)
                + $this->gate($station, 2, $at, $wetness)
                + $this->gate($station, 3, $at, $wetness)) / 3,
            'gate_opening_1' => $this->gate($station, 1, $at, $wetness),
            'gate_opening_2' => $this->gate($station, 2, $at, $wetness),
            'gate_opening_3' => $this->gate($station, 3, $at, $wetness),
            'gate_target' => ($this->gateTarget($station, 1, $at, $wetness)
                + $this->gateTarget($station, 2, $at, $wetness)
                + $this->gateTarget($station, 3, $at, $wetness)) / 3,
            /*
            | Three-phase current on each hoist, which is what the real AWGC
            | reports beside the opening. A motor at rest draws its holding
            | current and nothing more; hauling a leaf is where the amps are,
            | so the figure is tied to whether the gate is travelling — a
            | current that hummed along at full load with the gate parked
            | would be a picture of a machine that is not there.
            */
            'gate_current_r_1' => $this->gateCurrent($station, 1, $at, $wetness, 0),
            'gate_current_s_1' => $this->gateCurrent($station, 1, $at, $wetness, 1),
            'gate_current_t_1' => $this->gateCurrent($station, 1, $at, $wetness, 2),
            'gate_current_r_2' => $this->gateCurrent($station, 2, $at, $wetness, 0),
            'gate_current_s_2' => $this->gateCurrent($station, 2, $at, $wetness, 1),
            'gate_current_t_2' => $this->gateCurrent($station, 2, $at, $wetness, 2),
            'gate_current_r_3' => $this->gateCurrent($station, 3, $at, $wetness, 0),
            'gate_current_s_3' => $this->gateCurrent($station, 3, $at, $wetness, 1),
            'gate_current_t_3' => $this->gateCurrent($station, 3, $at, $wetness, 2),
            'gate_target_1' => $this->gateTarget($station, 1, $at, $wetness),
            'gate_target_2' => $this->gateTarget($station, 2, $at, $wetness),
            'gate_target_3' => $this->gateTarget($station, 3, $at, $wetness),
            'head_over_crest' => max(0.0, 0.35 + 0.25 * $slow + 1.1 * $wetness),

            /*
            | The logger reporting on itself, which every station does.
            |
            | The battery is a solar one: it climbs through the day, drains
            | overnight, and drains further through a wet week when the panel
            | sees nothing — which is exactly when a site cannot be reached.
            | The enclosure runs warmer than the air it stands in and holds its
            | damp after rain, because that is what a sealed box on a pole
            | does, and a humid enclosure is the reason a logger dies weeks
            | before anybody looks at it.
            */
            'logger_battery' => max(10.4, min(14.6,
                13.35 + 0.62 * max(-1.0, $diurnal) - 0.55 * $wetness + 0.18 * $slow + 0.04 * $jitter)),
            'logger_temperature' => 26.8 + 7.4 * $diurnal + 0.9 * $slow - 1.8 * $wetness + 0.3 * $jitter,
            'logger_humidity' => min(99.0, max(28.0,
                58 - 11 * $diurnal + 24 * $wetness + 2.5 * $jitter)),

            'rainfall' => $rainNow,
            'rainfall_24h' => $rain24h,
            'rainfall_intensity' => $rainNow,
            'temperature' => 24.6 + 4.6 * $diurnal + 0.8 * $slow - 1.4 * $wetness,
            'humidity' => min(99.0, max(52.0, 78 - 14 * $diurnal + 16 * $wetness + 2 * $jitter)),
            // The product sheet quotes m/s, so the model does too.
            'wind_speed' => max(0.2, 2.4 + 2.6 * max(0.0, $diurnal) + 0.7 * $fast + 1.7 * $wetness),
            'wind_direction' => fmod(180 + 120 * $slow + 40 * $fast + 360, 360),
            'solar_radiation' => max(0.0, 880 * max(0.0, $diurnal) * (1 - 0.65 * $wetness) + 15 * $jitter),
            'air_pressure' => 1009.5 + 1.8 * $slow - 2.4 * $wetness,

            /*
            | Illuminance is the sky's own witness: it follows the sun, and
            | cloud takes it away. Rain at midday can drop it from six figures
            | to a few thousand lux while the rain gauge climbs — which is the
            | pair the overcast/drizzle scene is read from.
            */
            'illuminance' => max(0.0, 108000 * max(0.0, $diurnal) * (1 - 0.88 * $wetness) + 40 * $jitter),

            // AWLR: depth above the sensor, the raw millimetres it reports, and
            // whether it is reporting them cleanly.
            'water_depth' => $station->type === 'water_level' && str_contains($station->code, 'hilir')
                ? max(0.15, 1.35 + 0.18 * $slow + 1.6 * $wetness)
                : max(0.5, 8.6 + 0.32 * $slow + 0.65 * $wetness),
            'raw_reading' => $station->type === 'water_level' && str_contains($station->code, 'hilir')
                ? round(1350 + 180 * $slow + 1600 * $wetness)
                : round(8600 + 320 * $slow + 650 * $wetness),
            'sensor_status' => $wetness > 0.85 && $fast > 0.7 ? 1.0 : 0.0,

            'pore_pressure' => 238 + 7.5 * $slow + 26 * $wetness + 1.5 * $jitter,
            'piezo_level' => 88.4 + 0.45 * $slow + 0.9 * $wetness + 0.05 * $jitter,
            'water_column' => 12.6 + 0.5 * $slow + 1.2 * $wetness,
            'well_level' => 84.8 + 0.4 * $slow + 0.8 * $wetness + 0.04 * $jitter,

            'seepage_flow' => max(0.6, 11.8 + 1.9 * $slow + 7.5 * $wetness + 0.6 * $jitter),
            'seepage_turbidity' => max(0.2, 3.4 + 1.1 * $slow + 9 * $wetness),
            'notch_head' => max(0.01, 0.086 + 0.012 * $slow + 0.05 * $wetness),

            'turbidity' => max(0.5, 7.5 + 2.5 * $slow + 42 * $wetness + 1.5 * $jitter),
            'ph' => 7.25 + 0.14 * $slow - 0.25 * $wetness + 0.03 * $jitter,
            'dissolved_oxygen' => 6.9 + 0.35 * $slow - 0.6 * $wetness,
            'conductivity' => 168 + 12 * $slow + 25 * $wetness,
            'sediment_load' => max(2.0, 42 + 9 * $slow + 165 * $wetness),
            'sediment_depth' => 1.42 + 0.05 * $slow + 0.09 * $wetness,

            'displacement_h' => -2.30 + 0.42 * $slow + 0.16 * $jitter + 0.4 * $wetness,
            'displacement_v' => 1.78 + 0.28 * $slow + 0.12 * $jitter,
            'displacement_l' => 0.94 + 0.22 * $slow + 0.1 * $jitter,
            'tilt' => 0.048 + 0.011 * $slow + 0.004 * $jitter,
            'settlement' => 4.6 + 0.35 * $slow + 0.1 * $jitter,
            'prisms_measured' => round(42 + 1.4 * $fast),
            'cycle_duration' => 11.5 + 1.4 * $fast + 0.6 * $jitter,

            // Piezometer raw side: the vibrating wire's own frequency and the
            // temperature the reading has to be corrected for.
            'frequency_raw' => 8420 + 120 * $slow + 260 * $wetness + 18 * $jitter,
            'sensor_temperature' => 26.4 + 1.2 * $diurnal + 0.4 * $slow - 0.5 * $wetness,

            // ADR: the survey figures the prism displacement is computed from.
            'distance' => 284.512 + 0.004 * $slow + 0.002 * $jitter,
            'angle_h' => fmod(126.4820 + 0.0009 * $slow + 0.0004 * $jitter + 360, 360),
            'angle_v' => 91.2460 + 0.0007 * $slow + 0.0003 * $jitter,
            'coord_e' => 712_486.204 + 0.0021 * $slow + 0.0008 * $jitter,
            'coord_n' => 9_783_512.860 + 0.0019 * $slow + 0.0007 * $jitter,
            'coord_h' => 103.482 + 0.0016 * $slow + 0.0006 * $jitter,

            /*
            | EWS runs on the downstream level, so it carries a copy of it, the
            | threshold it compares against, and what the siren is doing. Level
            | 0-8 is the vendor's scale: 0 quiet, 8 evacuation.
            */
            'source_value' => 27.30 + 0.18 * $slow + 0.5 * $wetness + 0.03 * $jitter,
            'warning_level' => 30.0,
            'alarm_level' => (float) max(0, min(8, (int) floor(($this->rainfall24h($at) - 20) / 12))),
            'siren_status' => $rain24h >= 80 ? 2.0 : ($rain24h >= 45 ? 1.0 : 0.0),

            // AWGC: the level it works against, the target it was given, and
            // the machinery that has to get there.
            'pool_level' => 93.70 + 0.32 * $slow + 0.65 * $wetness + 0.012 * $jitter,
            'motor_status' => abs($fast) > 0.55 ? 1.0 : 0.0,
            'gate_status' => $wetness > 0.35 ? (abs($fast) > 0.55 ? 1.0 : 2.0) : 0.0,

            'battery_voltage' => 12.9 + 0.42 * max(0.0, $diurnal) - 0.25 * $wetness + 0.05 * $jitter,
            'signal_strength' => -68 + 5 * $slow + 2.5 * $jitter - 4 * $wetness,
            'link_uptime' => min(100.0, 99.1 + 0.6 * $slow),
            'clients_connected' => max(1.0, round(9 + 3 * $fast)),
            'stream_bitrate' => max(0.6, 3.4 + 0.55 * $fast + 0.2 * $jitter),
            'frame_rate' => max(8.0, 25 + 2.5 * $fast),
            'siren_battery' => 93.5 + 3.5 * $slow + 1.2 * $jitter,
            'siren_range' => 1.85 + 0.06 * $slow,

            default => 50 + 8 * $slow + 3 * $jitter,
        };
    }

    /** Rain in mm/hour for the given moment. */
    public function rainIntensity(CarbonImmutable $at): float
    {
        $dayKey = $at->format('Y-m-d');
        $seasonal = in_array((int) $at->month, self::WET_SEASON_MONTHS, true) ? 1.0 : 0.45;

        $roll = $this->hashUnit($dayKey.':rain');
        if ($roll > 0.35 + 0.35 * $seasonal) {
            return 0.0; // dry day
        }

        // Convective rain: short afternoon/evening bursts.
        $startHour = 11 + 8 * $this->hashUnit($dayKey.':start');
        $duration = 0.8 + 2.6 * $this->hashUnit($dayKey.':duration');
        $peak = (2.5 + 26 * $this->hashUnit($dayKey.':peak')) * $seasonal;

        $hour = $at->hour + $at->minute / 60;
        $offset = ($hour - $startHour) / $duration;

        if ($offset < 0 || $offset > 1) {
            return 0.0;
        }

        return round($peak * sin($offset * M_PI) ** 2, 3);
    }

    /** Rolling 24-hour rainfall total in mm. */
    public function rainfall24h(CarbonImmutable $at): float
    {
        $total = 0.0;

        for ($i = 0; $i < 48; $i++) {
            $total += $this->rainIntensity($at->subMinutes($i * 30)) * 0.5;
        }

        return round($total, 2);
    }

    /** Smooth deterministic oscillator in [-1, 1]. */
    private function wave(string $seed, CarbonImmutable $at, float $periodMinutes): float
    {
        $phase = $this->hashUnit($seed) * M_PI * 2;
        $minutes = $at->getTimestamp() / 60;

        return sin($minutes / $periodMinutes * M_PI * 2 + $phase);
    }

    /** Stable pseudo-random number in [0, 1) derived from a string. */
    private function hashUnit(string $seed): float
    {
        return (crc32($seed) % 100000) / 100000;
    }
}
