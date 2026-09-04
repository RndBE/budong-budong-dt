<?php

namespace Database\Seeders;

use App\Models\Dam;
use App\Models\SensorReading;
use App\Models\SensorStation;
use App\Services\Telemetry\ReadingSimulator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Fills 30 days of history: hourly for the older stretch, every 15 minutes for
 * the last three days so the charts have detail where the operator looks.
 */
class ReadingSeeder extends Seeder
{
    public function __construct(
        private readonly ReadingSimulator $simulator,
    ) {}

    public function run(): void
    {
        $dam = Dam::query()->where('code', 'budong-budong')->firstOrFail();
        $stations = SensorStation::query()->where('dam_id', $dam->id)->with('metrics')->get();

        $now = CarbonImmutable::now()->startOfMinute();
        $coarseFrom = $now->subDays(30);
        $fineFrom = $now->subDays(3);

        SensorReading::query()->whereIn('sensor_station_id', $stations->pluck('id'))->delete();

        $total = 0;
        foreach ($stations as $station) {
            $total += $this->simulator->fill($station, $coarseFrom, $fineFrom->subHour(), 60);
            $total += $this->simulator->fill($station, $fineFrom, $now, 15);
        }

        $this->command?->info("  {$total} pembacaan sensor dibuat.");
    }
}
