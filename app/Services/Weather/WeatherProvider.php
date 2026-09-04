<?php

namespace App\Services\Weather;

use App\Models\Dam;

interface WeatherProvider
{
    /**
     * @return array{temperature_c: float, humidity: float, wind_kmh: float, rainfall_24h_mm: float,
     *               condition: string, condition_label: string, updated_at: string}
     */
    public function current(Dam $dam): array;
}
