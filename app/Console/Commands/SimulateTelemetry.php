<?php

namespace App\Console\Commands;

use App\Models\Dam;
use App\Models\SensorReading;
use App\Models\SensorStation;
use App\Services\AlertEvaluator;
use App\Services\Telemetry\ReadingSimulator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Keeps the demo data moving: appends readings from the newest stored sample
 * up to now, then re-evaluates thresholds. Scheduled every 5 minutes in
 * routes/console.php; harmless to run by hand.
 *
 * Once real loggers post to /api/ingest (or TELEMETRY_DRIVER=http is set),
 * stop scheduling this command.
 */
class SimulateTelemetry extends Command
{
    protected $signature = 'telemetry:simulate
                            {--step=15 : Interval antar sampel dalam menit}
                            {--hours=0 : Isi ulang sejarah sebanyak N jam ke belakang}';

    protected $description = 'Membuat pembacaan sensor sintetis sampai waktu sekarang';

    public function handle(ReadingSimulator $simulator, AlertEvaluator $evaluator): int
    {
        $dam = Dam::query()->where('code', config('dam.code'))->first();

        if (! $dam) {
            $this->error('Bendungan belum di-seed. Jalankan php artisan db:seed terlebih dahulu.');

            return self::FAILURE;
        }

        $step = max(1, (int) $this->option('step'));
        $hours = (int) $this->option('hours');
        $now = CarbonImmutable::now()->startOfMinute();
        $stations = SensorStation::query()->where('dam_id', $dam->id)->with('metrics')->get();

        $written = 0;

        foreach ($stations as $station) {
            if ($station->metrics->isEmpty()) {
                continue;
            }

            $last = SensorReading::query()
                ->where('sensor_station_id', $station->id)
                ->max('recorded_at');

            $from = $hours > 0
                ? $now->subHours($hours)
                : ($last ? CarbonImmutable::parse($last)->addMinutes($step) : $now->subDay());

            if ($from > $now) {
                continue;
            }

            $written += $simulator->fill($station, $from, $now, $step);
        }

        $raised = $evaluator->evaluateAll($dam->id);

        $this->info("{$written} pembacaan ditulis, ".count($raised).' peringatan baru.');

        return self::SUCCESS;
    }
}
