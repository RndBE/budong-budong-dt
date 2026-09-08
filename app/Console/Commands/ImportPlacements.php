<?php

namespace App\Console\Commands;

use App\Models\PanoramaHotspot;
use App\Models\SensorStation;
use Illuminate\Console\Command;

/**
 * Push the exported placement onto rows that already exist.
 *
 * `StationSeeder` reads `placements.php` only when it *creates* a row, because
 * an install that already has placements must keep them — a deploy may not
 * undo an afternoon somebody spent dragging prisms onto the real bays. That is
 * the right default, and it is also why placement made on one machine never
 * reaches a server where those rows are already there: the seeder walks past
 * them.
 *
 * This is the other direction, asked for out loud. It overwrites what is on
 * the target with what the file says, so it prints every change and refuses to
 * run unattended without `--force`. Narrow it with `--station=` when only one
 * panorama was worked on.
 */
class ImportPlacements extends Command
{
    protected $signature = 'placements:import
        {--station=* : Batasi ke kode stasiun tertentu}
        {--dry-run : Tampilkan perubahannya saja, jangan tulis}
        {--force : Jangan tanya konfirmasi}';

    protected $description = 'Terapkan berkas penempatan ke penanda yang sudah ada (menimpa posisi di sini)';

    public function handle(): int
    {
        $path = database_path('seeders/data/placements.php');

        if (! is_file($path)) {
            $this->error('Berkas penempatan belum ada: '.$path);

            return self::FAILURE;
        }

        /** @var array<string, array<string, mixed>> $placements */
        $placements = (array) require $path;
        $only = (array) $this->option('station');

        if ($only !== []) {
            $placements = array_intersect_key($placements, array_flip($only));
        }

        $changes = $this->plan($placements);

        if ($changes === []) {
            $this->info('Semua penanda sudah sama dengan berkas. Tidak ada yang diubah.');

            return self::SUCCESS;
        }

        foreach ($changes as $change) {
            $this->line(sprintf('  %-22s %-28s %s', $change['station'], $change['label'], $change['detail']));
        }

        $this->newLine();
        $this->warn(count($changes).' penanda akan ditimpa dengan isi berkas.');

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Lanjutkan?', false)) {
            $this->line('Dibatalkan.');

            return self::SUCCESS;
        }

        foreach ($changes as $change) {
            ($change['apply'])();
        }

        $this->info(count($changes).' penanda diperbarui.');

        return self::SUCCESS;
    }

    /**
     * What would change, and how to change it.
     *
     * Built before anything is written so the operator sees the whole list
     * first — a placement overwrite is a survey figure moving, and it should
     * never be the surprise half of another command.
     *
     * @param  array<string, array<string, mixed>>  $placements
     * @return list<array{station: string, label: string, detail: string, apply: callable}>
     */
    private function plan(array $placements): array
    {
        $changes = [];

        foreach ($placements as $code => $placed) {
            $station = SensorStation::query()->where('code', $code)->with('hotspots')->first();

            if (! $station) {
                $this->warn("Stasiun {$code} tidak ada di sini — dilewati.");

                continue;
            }

            if (isset($placed['sphere'])) {
                $yaw = (float) $placed['sphere']['yaw'];
                $pitch = (float) $placed['sphere']['pitch'];

                if (! $this->same($station->sphere_yaw, $yaw) || ! $this->same($station->sphere_pitch, $pitch)) {
                    $changes[] = [
                        'station' => $code,
                        'label' => '(penanda stasiun)',
                        'detail' => $this->move($station->sphere_yaw, $station->sphere_pitch, $yaw, $pitch),
                        'apply' => function () use ($station, $yaw, $pitch) {
                            $station->forceFill(['sphere_yaw' => $yaw, 'sphere_pitch' => $pitch])->save();
                        },
                    ];
                }
            }

            foreach ($placed['hotspots'] ?? [] as $label => $spot) {
                $hotspot = $station->hotspots->firstWhere('label', $label);

                if (! $hotspot) {
                    $this->warn("Titik {$code}/{$label} tidak ada di sini — dilewati.");

                    continue;
                }

                $yaw = (float) $spot['yaw'];
                $pitch = (float) $spot['pitch'];
                $places = $spot['places'] ?? null;
                $moved = ! $this->same($hotspot->yaw, $yaw) || ! $this->same($hotspot->pitch, $pitch);
                $nudged = ($hotspot->meta['places'] ?? null) != $places;

                if (! $moved && ! $nudged) {
                    continue;
                }

                $detail = $moved ? $this->move($hotspot->yaw, $hotspot->pitch, $yaw, $pitch) : 'posisi tetap';

                if ($nudged) {
                    $detail .= sprintf(' + %d nudge patok', count((array) $places));
                }

                $changes[] = [
                    'station' => $code,
                    'label' => $label,
                    'detail' => $detail,
                    'apply' => function () use ($hotspot, $yaw, $pitch, $places) {
                        $meta = $hotspot->meta ?? [];

                        if ($places) {
                            $meta['places'] = $places;
                        } else {
                            unset($meta['places']);
                        }

                        $hotspot->forceFill([
                            'yaw' => $yaw,
                            'pitch' => $pitch,
                            'meta' => $meta === [] ? null : $meta,
                        ])->save();
                    },
                ];
            }
        }

        return $changes;
    }

    /** Angles are stored to three decimals; comparing floats exactly is noise. */
    private function same(?float $left, float $right): bool
    {
        return $left !== null && abs($left - $right) < 0.001;
    }

    private function move(?float $fromYaw, ?float $fromPitch, float $toYaw, float $toPitch): string
    {
        return sprintf(
            '%s, %s  ->  %s, %s',
            $fromYaw ?? '—',
            $fromPitch ?? '—',
            round($toYaw, 3),
            round($toPitch, 3),
        );
    }
}
