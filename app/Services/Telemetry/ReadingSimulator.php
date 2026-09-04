<?php

namespace App\Services\Telemetry;

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
 * Replace this with real data by pointing TELEMETRY_DRIVER at the upstream
 * API or by pushing to POST /api/ingest — nothing else has to change.
 */
class ReadingSimulator
{
    /** Rain drives level, inflow, seepage, turbidity and pore pressure alike. */
    private const WET_SEASON_MONTHS = [11, 12, 1, 2, 3, 4];

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
            'gate_opening' => max(0.0, min(100.0, 18 + 10 * $slow + 45 * $wetness)),
            'head_over_crest' => max(0.0, 0.35 + 0.25 * $slow + 1.1 * $wetness),

            'rainfall' => $rainNow,
            'rainfall_24h' => $rain24h,
            'rainfall_intensity' => $rainNow,
            'temperature' => 24.6 + 4.6 * $diurnal + 0.8 * $slow - 1.4 * $wetness,
            'humidity' => min(99.0, max(52.0, 78 - 14 * $diurnal + 16 * $wetness + 2 * $jitter)),
            'wind_speed' => max(0.4, 8.5 + 9.5 * max(0.0, $diurnal) + 2.5 * $fast + 6 * $wetness),
            'wind_direction' => fmod(180 + 120 * $slow + 40 * $fast + 360, 360),
            'solar_radiation' => max(0.0, 880 * max(0.0, $diurnal) * (1 - 0.65 * $wetness) + 15 * $jitter),
            'air_pressure' => 1009.5 + 1.8 * $slow - 2.4 * $wetness,

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
