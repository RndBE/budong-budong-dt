<?php

namespace App\Console\Commands;

use App\Models\PanoramaHotspot;
use App\Models\SensorStation;
use App\Services\MonitoringService;
use Illuminate\Console\Command;

/**
 * Write where everything has been dragged to into a file the repository keeps.
 *
 * Placement lives in the database: a station pin's `sphere_yaw`/`sphere_pitch`,
 * a hotspot's own angles, and the per-prism nudges in `meta.places`. Push the
 * code to a server and none of it goes — the seeder there builds the catalogue
 * with its *estimated* angles off the render, and an afternoon of placing
 * prisms on the real bays is not in the box.
 *
 * This exports the current placement to `database/seeders/data/placements.php`,
 * which `StationSeeder` reads when it creates a row. Commit the file and every
 * environment comes up with the survey where it belongs.
 *
 * It is only ever read on *create*: an install that already has placements
 * keeps them, which is the same rule the seeder has always followed for a
 * dragged marker.
 */
class ExportPlacements extends Command
{
    protected $signature = 'placements:export';

    protected $description = 'Simpan posisi penanda dan patok ke berkas seeder agar ikut ter-push';

    public function handle(MonitoringService $monitoring): int
    {
        $dam = $monitoring->dam();

        $stations = SensorStation::query()
            ->where('dam_id', $dam->id)
            ->with('hotspots')
            ->orderBy('code')
            ->get();

        $placements = [];
        $pins = 0;
        $spots = 0;

        foreach ($stations as $station) {
            $entry = [];

            if ($station->sphere_yaw !== null || $station->sphere_pitch !== null) {
                $entry['sphere'] = [
                    'yaw' => (float) $station->sphere_yaw,
                    'pitch' => (float) $station->sphere_pitch,
                ];
                $pins++;
            }

            foreach ($station->hotspots as $hotspot) {
                $spot = [
                    'yaw' => (float) $hotspot->yaw,
                    'pitch' => (float) $hotspot->pitch,
                ];

                // The per-prism nudges, which are the slow part to redo.
                if (! empty($hotspot->meta['places'])) {
                    $spot['places'] = $hotspot->meta['places'];
                }

                $entry['hotspots'][$hotspot->label] = $spot;
                $spots++;
            }

            if ($entry !== []) {
                $placements[$station->code] = $entry;
            }
        }

        $path = database_path('seeders/data/placements.php');

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, $this->render($placements));

        $this->info("{$pins} penanda stasiun dan {$spots} titik panorama ditulis ke ".
            str_replace(base_path().DIRECTORY_SEPARATOR, '', $path));
        $this->line('Commit berkas itu agar penempatannya ikut ter-push.');

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $placements */
    private function render(array $placements): string
    {
        $body = var_export($placements, true);

        return <<<PHP
        <?php

        /*
        | Where the markers actually are, exported by `php artisan placements:export`.
        |
        | The catalogue in StationSeeder carries *estimated* angles read off the
        | renders, which are not surveyed. This file carries the corrections
        | somebody made on the stage, so a fresh install comes up with them
        | rather than with the estimate.
        |
        | Generated — re-run the command rather than editing it by hand.
        */

        return {$body};

        PHP;
    }
}
