<?php

namespace App\Services\Telemetry;

use App\Models\SensorStation;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Everything the UI knows about live values goes through this contract, so the
 * data source can be swapped (local table, upstream logger API, gateway) by
 * binding a different implementation in TelemetryServiceProvider.
 */
interface TelemetryProvider
{
    /**
     * Latest value per metric for one station.
     *
     * @return array<string, array{value: float, recorded_at: CarbonInterface, quality: string}>
     */
    public function latest(SensorStation $station): array;

    /**
     * Latest value per metric for many stations at once.
     *
     * @param  Collection<int, SensorStation>  $stations
     * @return array<int, array<string, array{value: float, recorded_at: CarbonInterface, quality: string}>>
     */
    public function latestForStations(Collection $stations): array;

    /**
     * Down-sampled time series for one metric.
     *
     * @return list<array{t: string, v: float}>
     */
    public function series(
        SensorStation $station,
        string $metricKey,
        CarbonInterface $from,
        CarbonInterface $to,
        int $maxPoints = 240,
    ): array;
}
