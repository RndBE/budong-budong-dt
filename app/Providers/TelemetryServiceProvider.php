<?php

namespace App\Providers;

use App\Services\Telemetry\DatabaseTelemetryProvider;
use App\Services\Telemetry\HttpTelemetryProvider;
use App\Services\Telemetry\TelemetryProvider;
use App\Services\Weather\HttpWeatherProvider;
use App\Services\Weather\StationWeatherProvider;
use App\Services\Weather\WeatherProvider;
use Illuminate\Support\ServiceProvider;

class TelemetryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DatabaseTelemetryProvider::class);

        $this->app->singleton(TelemetryProvider::class, fn ($app) => match (config('telemetry.driver')) {
            'http' => new HttpTelemetryProvider($app->make(DatabaseTelemetryProvider::class)),
            default => $app->make(DatabaseTelemetryProvider::class),
        });

        $this->app->singleton(StationWeatherProvider::class);

        $this->app->singleton(WeatherProvider::class, fn ($app) => match (config('telemetry.weather.driver')) {
            'http' => new HttpWeatherProvider($app->make(StationWeatherProvider::class)),
            default => $app->make(StationWeatherProvider::class),
        });
    }
}
