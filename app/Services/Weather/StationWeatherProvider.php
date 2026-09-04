<?php

namespace App\Services\Weather;

use App\Models\Dam;
use App\Models\SensorStation;
use App\Services\Telemetry\TelemetryProvider;
use App\Support\SolarClock;
use Carbon\CarbonImmutable;

/**
 * Derives the header weather strip from the on-site weather station (AWR)
 * instead of an external forecast service. Anything the station does not
 * report is estimated from the solar clock so the strip never goes blank.
 */
class StationWeatherProvider implements WeatherProvider
{
    public function __construct(
        private readonly TelemetryProvider $telemetry,
    ) {}

    public function current(Dam $dam): array
    {
        $station = SensorStation::query()
            ->where('dam_id', $dam->id)
            ->whereIn('type', ['weather', 'rainfall'])
            ->orderByRaw("CASE WHEN type = 'weather' THEN 0 ELSE 1 END")
            ->first();

        $readings = $station ? $this->telemetry->latest($station) : [];
        $clock = new SolarClock($dam->latitude, $dam->longitude, $dam->timezone);
        $sun = $clock->state();
        $daylight = $sun['scene']['daylight'];

        $temperature = $readings['temperature']['value'] ?? round(23.5 + 5.5 * $daylight, 1);
        $humidity = $readings['humidity']['value'] ?? round(88 - 18 * $daylight);
        $wind = $readings['wind_speed']['value'] ?? round(6 + 14 * $daylight, 1);
        $rainfall = $readings['rainfall_24h']['value'] ?? $readings['rainfall']['value'] ?? 0.0;
        $recordedAt = $readings['temperature']['recorded_at'] ?? CarbonImmutable::now();

        $condition = $this->condition((float) $rainfall, (float) $humidity, $daylight);

        return [
            'temperature_c' => round((float) $temperature, 1),
            'humidity' => round((float) $humidity),
            'wind_kmh' => round((float) $wind, 1),
            'rainfall_24h_mm' => round((float) $rainfall, 1),
            'condition' => $condition['key'],
            'condition_label' => $condition['label'],
            'station' => $station?->code,
            'updated_at' => $recordedAt->toIso8601String(),
        ];
    }

    private function condition(float $rainfall, float $humidity, float $daylight): array
    {
        return match (true) {
            $rainfall >= 20 => ['key' => 'hujan-lebat', 'label' => 'Hujan Lebat'],
            $rainfall >= 5 => ['key' => 'hujan', 'label' => 'Hujan'],
            $rainfall > 0.2 => ['key' => 'hujan-ringan', 'label' => 'Hujan Ringan'],
            $humidity >= 90 => ['key' => 'berkabut', 'label' => 'Berkabut'],
            $humidity >= 80 => ['key' => 'berawan', 'label' => 'Berawan'],
            $daylight < 0.1 => ['key' => 'cerah-malam', 'label' => 'Cerah'],
            $humidity >= 70 => ['key' => 'cerah-berawan', 'label' => 'Cerah Berawan'],
            default => ['key' => 'cerah', 'label' => 'Cerah'],
        };
    }
}
