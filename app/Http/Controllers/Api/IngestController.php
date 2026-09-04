<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SensorReading;
use App\Models\SensorStation;
use App\Services\AlertEvaluator;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Device / gateway ingest.
 *
 *   POST /api/ingest
 *   X-Ingest-Token: <TELEMETRY_INGEST_TOKEN>
 *   {
 *     "station": "awlr-hulu",
 *     "recorded_at": "2026-09-03T09:20:00+08:00",
 *     "metrics": { "water_level": 93.881, "inflow": 12.36 }
 *   }
 *
 * Readings land in the same table the seeder and simulator write to, so the
 * dashboard picks them up without any other change.
 */
class IngestController extends Controller
{
    public function __invoke(Request $request, AlertEvaluator $evaluator): JsonResponse
    {
        $expected = config('telemetry.ingest_token');

        if (! $expected || ! hash_equals((string) $expected, (string) $request->header('X-Ingest-Token'))) {
            throw new AccessDeniedHttpException('Token ingest tidak valid.');
        }

        $data = $request->validate([
            'station' => ['required', 'string'],
            'recorded_at' => ['nullable', 'date'],
            'quality' => ['nullable', 'in:good,estimated,suspect'],
            'metrics' => ['required', 'array', 'min:1'],
            'metrics.*' => ['numeric'],
        ]);

        /** @var SensorStation $station */
        $station = SensorStation::query()
            ->where('code', $data['station'])
            ->orWhere('telemetry_channel', $data['station'])
            ->firstOrFail();

        $recordedAt = isset($data['recorded_at']) ? Carbon::parse($data['recorded_at']) : now();
        $known = $station->metrics()->pluck('key')->all();
        $stored = [];
        $ignored = [];

        foreach ($data['metrics'] as $key => $value) {
            if (! in_array($key, $known, true)) {
                $ignored[] = $key;

                continue;
            }

            SensorReading::query()->updateOrCreate(
                [
                    'sensor_station_id' => $station->id,
                    'metric_key' => $key,
                    'recorded_at' => $recordedAt,
                ],
                [
                    'value' => $value,
                    'quality' => $data['quality'] ?? 'good',
                ],
            );

            $stored[] = $key;
        }

        $station->forceFill(['is_online' => true])->save();
        $raised = $evaluator->evaluateStation($station->fresh('metrics'));

        return response()->json([
            'station' => $station->code,
            'recorded_at' => $recordedAt->toIso8601String(),
            'stored' => $stored,
            'ignored' => $ignored,
            'alerts_raised' => $raised,
        ], 201);
    }
}
