<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\Report;
use App\Models\SensorReading;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/**
 * Renders the monitoring log for a period into a PDF (dompdf) or a CSV of the
 * raw readings, and records it in `reports` so the page can list downloads.
 */
class ReportBuilder
{
    public function __construct(
        private readonly MonitoringService $monitoring,
    ) {}

    public function damId(): int
    {
        return $this->monitoring->dam()->id;
    }

    public function build(
        string $period,
        string $start,
        string $end,
        string $format,
        array $sections,
        ?string $generatedBy = null,
    ): Report {
        $dam = $this->monitoring->dam();
        $from = CarbonImmutable::parse($start, $dam->timezone)->startOfDay();
        $to = CarbonImmutable::parse($end, $dam->timezone)->endOfDay();

        $title = sprintf(
            'Laporan %s %s — %s',
            ucfirst($period),
            $from->format('d/m/Y'),
            $to->format('d/m/Y'),
        );

        $dashboard = $this->monitoring->dashboard();
        $stations = $this->monitoring->stationsWithMetrics();

        $alerts = Alert::query()
            ->where('dam_id', $dam->id)
            ->whereBetween('triggered_at', [$from, $to])
            ->with('station:id,code,name')
            ->orderByDesc('triggered_at')
            ->get();

        $summary = [
            'health_score' => $dashboard['health']['score'],
            'primary' => $dashboard['primary'],
            'alerts_total' => $alerts->count(),
            'alerts_by_level' => $alerts->groupBy('level')->map->count()->all(),
            'stations_total' => $stations->count(),
        ];

        $directory = 'reports';
        $filename = sprintf('%s-%s.%s', $from->format('Ymd'), str()->random(6), $format);
        $path = "{$directory}/{$filename}";

        if ($format === 'csv') {
            Storage::disk('local')->put($path, $this->csv($stations, $from, $to));
        } else {
            $pdf = Pdf::loadView('reports.monitoring', [
                'dam' => $dam,
                'title' => $title,
                'from' => $from,
                'to' => $to,
                'sections' => $sections,
                'summary' => $summary,
                'dashboard' => $dashboard,
                'stations' => $stations,
                'alerts' => $alerts,
                'markers' => $this->monitoring->markers(),
            ])->setPaper('a4', 'portrait');

            Storage::disk('local')->put($path, $pdf->output());
        }

        return Report::query()->create([
            'dam_id' => $dam->id,
            'title' => $title,
            'period' => $period,
            'period_start' => $from->toDateString(),
            'period_end' => $to->toDateString(),
            'format' => $format,
            'sections' => $sections,
            'summary' => $summary,
            'file_path' => $path,
            'generated_by' => $generatedBy,
        ]);
    }

    private function csv($stations, CarbonImmutable $from, CarbonImmutable $to): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['stasiun', 'kode', 'parameter', 'satuan', 'nilai', 'kualitas', 'waktu']);

        $labels = [];
        foreach ($stations as $station) {
            foreach ($station->metrics as $metric) {
                $labels["{$station->id}:{$metric->key}"] = [$station->name, $station->code, $metric->label, $metric->unit];
            }
        }

        SensorReading::query()
            ->whereIn('sensor_station_id', $stations->pluck('id'))
            ->whereBetween('recorded_at', [$from, $to])
            ->orderBy('recorded_at')
            ->chunk(2000, function ($readings) use ($handle, $labels) {
                foreach ($readings as $reading) {
                    $label = $labels["{$reading->sensor_station_id}:{$reading->metric_key}"] ?? null;
                    if (! $label) {
                        continue;
                    }

                    fputcsv($handle, [
                        $label[0],
                        $label[1],
                        $label[2],
                        $label[3],
                        $reading->value,
                        $reading->quality,
                        $reading->recorded_at->toDateTimeString(),
                    ]);
                }
            });

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return (string) $csv;
    }
}
