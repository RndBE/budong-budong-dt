<?php

namespace App\Services\Telemetry;

use App\Models\SensorStation;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pulls live values from an upstream logger API.
 *
 * Set TELEMETRY_DRIVER=http plus TELEMETRY_BASE_URL/TELEMETRY_TOKEN in .env.
 * Endpoint templates and field renames live in config/telemetry.php, so
 * adapting to a different vendor payload is configuration rather than code.
 * Any upstream failure degrades to the locally stored readings instead of
 * blanking the dashboard.
 */
class HttpTelemetryProvider implements TelemetryProvider
{
    public function __construct(
        private readonly DatabaseTelemetryProvider $fallback,
    ) {}

    public function latest(SensorStation $station): array
    {
        $channel = $station->telemetry_channel ?: $station->code;
        $cacheKey = "telemetry:latest:{$channel}";

        try {
            $payload = Cache::remember($cacheKey, (int) config('telemetry.http.cache_ttl'), function () use ($channel) {
                $endpoint = str_replace('{station}', $channel, (string) config('telemetry.http.endpoints.latest'));

                return $this->request($endpoint);
            });
        } catch (Throwable $exception) {
            Log::warning('Telemetry latest fetch failed, using stored readings.', [
                'station' => $station->code,
                'error' => $exception->getMessage(),
            ]);

            return $this->fallback->latest($station);
        }

        $readings = $this->normaliseLatest($payload);

        return $readings === [] ? $this->fallback->latest($station) : $readings;
    }

    public function latestForStations(Collection $stations): array
    {
        $result = [];

        foreach ($stations as $station) {
            $result[$station->id] = $this->latest($station);
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
        $channel = $station->telemetry_channel ?: $station->code;

        try {
            $endpoint = str_replace(
                ['{station}', '{metric}', '{from}', '{to}'],
                [$channel, $this->upstreamField($metricKey), $from->toIso8601String(), $to->toIso8601String()],
                (string) config('telemetry.http.endpoints.series'),
            );

            $payload = $this->request($endpoint);
        } catch (Throwable $exception) {
            Log::warning('Telemetry series fetch failed, using stored readings.', [
                'station' => $station->code,
                'metric' => $metricKey,
                'error' => $exception->getMessage(),
            ]);

            return $this->fallback->series($station, $metricKey, $from, $to, $maxPoints);
        }

        $points = [];
        foreach ($payload['data'] ?? $payload['series'] ?? [] as $point) {
            $timestamp = $point['t'] ?? $point['timestamp'] ?? $point['recorded_at'] ?? null;
            $value = $point['v'] ?? $point['value'] ?? null;

            if ($timestamp === null || $value === null) {
                continue;
            }

            $points[] = ['t' => Carbon::parse($timestamp)->toIso8601String(), 'v' => (float) $value];
        }

        return $points === []
            ? $this->fallback->series($station, $metricKey, $from, $to, $maxPoints)
            : $points;
    }

    private function request(string $endpoint): array
    {
        $response = Http::baseUrl((string) config('telemetry.http.base_url'))
            ->timeout((int) config('telemetry.http.timeout'))
            ->acceptJson()
            ->when(
                (bool) config('telemetry.http.token'),
                fn ($client) => $client->withToken((string) config('telemetry.http.token')),
            )
            ->get($endpoint)
            ->throw();

        return $response->json() ?? [];
    }

    /** @return array<string, array{value: float, recorded_at: CarbonInterface, quality: string}> */
    private function normaliseLatest(array $payload): array
    {
        $metrics = $payload['metrics'] ?? $payload['data'] ?? $payload;
        $recordedAt = isset($payload['recorded_at']) ? Carbon::parse($payload['recorded_at']) : Carbon::now();
        $fieldMap = array_flip((array) config('telemetry.http.field_map', []));

        $readings = [];
        foreach ($metrics as $key => $entry) {
            if (! is_string($key)) {
                continue;
            }

            $localKey = $fieldMap[$key] ?? $key;
            $value = is_array($entry) ? ($entry['value'] ?? null) : $entry;

            if (! is_numeric($value)) {
                continue;
            }

            $readings[$localKey] = [
                'value' => (float) $value,
                'recorded_at' => isset($entry['recorded_at']) ? Carbon::parse($entry['recorded_at']) : $recordedAt,
                'quality' => is_array($entry) ? ($entry['quality'] ?? 'good') : 'good',
            ];
        }

        return $readings;
    }

    private function upstreamField(string $metricKey): string
    {
        $map = (array) config('telemetry.http.field_map', []);

        return $map[$metricKey] ?? $metricKey;
    }
}
