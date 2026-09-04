<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\SensorStation;
use App\Services\Telemetry\TelemetryProvider;

/**
 * Turns threshold breaches into `alerts` rows, and clears the ones whose
 * value has come back inside its normal band.
 */
class AlertEvaluator
{
    public function __construct(
        private readonly TelemetryProvider $telemetry,
    ) {}

    /** @return list<string> titles of the alerts raised by this pass */
    public function evaluateStation(SensorStation $station): array
    {
        $readings = $this->telemetry->latest($station);
        $raised = [];

        foreach ($station->metrics as $metric) {
            $value = $readings[$metric->key]['value'] ?? null;
            if ($value === null) {
                continue;
            }

            $status = $metric->statusFor((float) $value);
            $open = Alert::query()
                ->where('sensor_station_id', $station->id)
                ->where('metric_key', $metric->key)
                ->active()
                ->first();

            if ($status === 'normal') {
                $open?->update(['resolved_at' => now()]);

                continue;
            }

            $threshold = match ($status) {
                'bahaya' => $metric->critical_threshold,
                'siaga' => $metric->alert_threshold,
                default => $metric->warning_threshold ?? $metric->normal_max,
            };

            $title = sprintf('%s %s di %s', ucfirst($status), $metric->label, $station->short_name ?? $station->name);

            if ($open) {
                $open->update([
                    'level' => $status,
                    'value' => $value,
                    'threshold' => $threshold,
                    'title' => $title,
                ]);

                continue;
            }

            Alert::query()->create([
                'dam_id' => $station->dam_id,
                'sensor_station_id' => $station->id,
                'level' => $status,
                'category' => $station->type,
                'title' => $title,
                'message' => sprintf(
                    '%s terbaca %s %s, melewati ambang %s %s.',
                    $metric->label,
                    number_format((float) $value, $metric->decimals, ',', '.'),
                    (string) $metric->unit,
                    $threshold === null ? '-' : number_format((float) $threshold, $metric->decimals, ',', '.'),
                    (string) $metric->unit,
                ),
                'metric_key' => $metric->key,
                'value' => $value,
                'threshold' => $threshold,
                'triggered_at' => now(),
            ]);

            $raised[] = $title;
        }

        return $raised;
    }

    /** @return list<string> */
    public function evaluateAll(int $damId): array
    {
        $raised = [];

        SensorStation::query()
            ->where('dam_id', $damId)
            ->with('metrics')
            ->get()
            ->each(function (SensorStation $station) use (&$raised) {
                $raised = array_merge($raised, $this->evaluateStation($station));
            });

        return $raised;
    }
}
