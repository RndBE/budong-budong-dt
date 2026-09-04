<?php

namespace App\Services\Telemetry;

use App\Models\SensorReading;
use App\Models\SensorStation;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reads whatever is stored in `sensor_readings` — seeded history, the
 * simulator command, or payloads pushed to POST /api/ingest.
 */
class DatabaseTelemetryProvider implements TelemetryProvider
{
    public function latest(SensorStation $station): array
    {
        return $this->latestForStations(collect([$station]))[$station->id] ?? [];
    }

    public function latestForStations(Collection $stations): array
    {
        $ids = $stations->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        $newest = DB::table('sensor_readings')
            ->selectRaw('sensor_station_id, metric_key, MAX(recorded_at) as recorded_at')
            ->whereIn('sensor_station_id', $ids)
            ->groupBy('sensor_station_id', 'metric_key');

        $rows = SensorReading::query()
            ->select('sensor_readings.*')
            ->joinSub($newest, 'newest', function ($join) {
                $join->on('sensor_readings.sensor_station_id', '=', 'newest.sensor_station_id')
                    ->on('sensor_readings.metric_key', '=', 'newest.metric_key')
                    ->on('sensor_readings.recorded_at', '=', 'newest.recorded_at');
            })
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[$row->sensor_station_id][$row->metric_key] = [
                'value' => (float) $row->value,
                'recorded_at' => $row->recorded_at,
                'quality' => $row->quality,
            ];
        }

        return $result;
    }

    public function series(
        SensorStation $station,
        string $metricKey,
        CarbonInterface $from,
        CarbonInterface $to,
        int $maxPoints = 240,
    ): array {
        $rows = SensorReading::query()
            ->where('sensor_station_id', $station->id)
            ->where('metric_key', $metricKey)
            ->whereBetween('recorded_at', [$from, $to])
            ->orderBy('recorded_at')
            ->get(['value', 'recorded_at']);

        return $this->downsample($rows, $maxPoints);
    }

    /** Keep the shape of the curve while capping the payload size. */
    private function downsample(Collection $rows, int $maxPoints): array
    {
        $total = $rows->count();

        if ($total === 0) {
            return [];
        }

        $step = (int) max(1, ceil($total / $maxPoints));
        $points = [];

        foreach ($rows->values() as $index => $row) {
            if ($index % $step !== 0 && $index !== $total - 1) {
                continue;
            }

            $points[] = [
                't' => $row->recorded_at->toIso8601String(),
                'v' => round((float) $row->value, 4),
            ];
        }

        return $points;
    }
}
