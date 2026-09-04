<?php

namespace App\Services\Weather;

use App\Models\Dam;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Optional external forecast source (WEATHER_DRIVER=http). Expects an
 * OpenWeather-shaped payload; falls back to the on-site station when the
 * request fails so the header never renders empty values.
 */
class HttpWeatherProvider implements WeatherProvider
{
    public function __construct(
        private readonly StationWeatherProvider $fallback,
    ) {}

    public function current(Dam $dam): array
    {
        try {
            $payload = Cache::remember(
                "weather:{$dam->code}",
                (int) config('telemetry.weather.cache_ttl'),
                fn () => Http::baseUrl((string) config('telemetry.weather.base_url'))
                    ->timeout(8)
                    ->acceptJson()
                    ->get('/weather', [
                        'lat' => $dam->latitude,
                        'lon' => $dam->longitude,
                        'units' => 'metric',
                        'lang' => 'id',
                        'appid' => config('telemetry.weather.api_key'),
                    ])
                    ->throw()
                    ->json(),
            );
        } catch (Throwable $exception) {
            Log::warning('Weather fetch failed, using on-site station.', ['error' => $exception->getMessage()]);

            return $this->fallback->current($dam);
        }

        $onSite = $this->fallback->current($dam);

        return [
            'temperature_c' => round((float) ($payload['main']['temp'] ?? $onSite['temperature_c']), 1),
            'humidity' => round((float) ($payload['main']['humidity'] ?? $onSite['humidity'])),
            'wind_kmh' => round((float) ($payload['wind']['speed'] ?? 0) * 3.6, 1),
            'rainfall_24h_mm' => $onSite['rainfall_24h_mm'],
            'condition' => $payload['weather'][0]['main'] ?? $onSite['condition'],
            'condition_label' => ucwords($payload['weather'][0]['description'] ?? $onSite['condition_label']),
            'station' => $onSite['station'],
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
