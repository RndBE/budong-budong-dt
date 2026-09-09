<?php

namespace Database\Seeders;

use App\Models\Dam;
use App\Models\PanoramaHotspot;
use App\Models\SensorMetric;
use App\Models\SensorStation;
use Illuminate\Database\Seeder;

/**
 * The instrumentation catalogue: one row per panorama in
 * `Panoramic 360 fix`, positioned on the map render in percent coordinates
 * (map_x / map_y) so markers keep their place while the stage pans and zooms.
 */
class StationSeeder extends Seeder
{
    /**
     * The three channels every logger reports about itself.
     *
     * Not what the instrument measures — what the box measures about its own
     * condition. A flat battery or a humid enclosure is the reason a station
     * stops reporting, and it shows up here days before it does anywhere else,
     * so every station that has a logger carries them.
     *
     * Named `logger_*` because a weather station already reports the air's
     * temperature and humidity, and those are a different thing entirely.
     */
    private const LOGGER_METRICS = [
        ['key' => 'logger_battery', 'label' => 'Baterai Logger', 'unit' => 'V', 'decimals' => 2, 'normal_min' => 11.8, 'normal_max' => 14.6],
        ['key' => 'logger_temperature', 'label' => 'Suhu Logger', 'unit' => '°C', 'decimals' => 1, 'normal_min' => 5, 'normal_max' => 55, 'warning_threshold' => 60, 'alert_threshold' => 70],
        ['key' => 'logger_humidity', 'label' => 'Kelembapan Logger', 'unit' => '%', 'decimals' => 1, 'normal_min' => 0, 'normal_max' => 75, 'warning_threshold' => 85, 'alert_threshold' => 95],
    ];

    /**
     * Hotspot ids kept by this run, per station.
     *
     * @var array<int, list<int>>
     */
    private array $keptHotspots = [];

    /**
     * Where the markers actually are, if anybody has exported it.
     *
     * The catalogue below carries angles read off the renders, which are not
     * surveyed — estimates, and said to be. This file carries the corrections
     * somebody made on the stage (`php artisan placements:export`), so a fresh
     * install comes up with the survey rather than with the guess.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $placements = [];

    public function run(): void
    {
        $dam = Dam::query()->where('code', 'budong-budong')->firstOrFail();
        $keptStations = [];
        $exported = database_path('seeders/data/placements.php');
        $this->placements = is_file($exported) ? (array) require $exported : [];

        foreach ($this->catalogue() as $definition) {
            $metrics = $definition['metrics'] ?? [];
            $hotspots = $definition['hotspots'] ?? [];
            unset($definition['metrics'], $definition['hotspots']);

            $placed = $this->placements[$definition['code']] ?? [];

            /** @var SensorStation $station */
            $station = SensorStation::query()->firstOrNew(['code' => $definition['code']]);

            /*
            | Where a pin sits in the base panorama is placement, not
            | catalogue, so it is written once when the row is new and never
            | again — the same rule the hotspots follow. Dragging a pin on the
            | stage must survive the next seed.
            */
            if (! $station->exists && isset($placed['sphere'])) {
                $station->forceFill([
                    'sphere_yaw' => $placed['sphere']['yaw'],
                    'sphere_pitch' => $placed['sphere']['pitch'],
                ]);
            }

            $station->fill($definition + ['dam_id' => $dam->id])->save();

            /*
            | The logger's own channels come after the instrument's, on every
            | station that has any at all — the overview panorama has no box on
            | a pole, and giving it three parameters would put it in the
            | analytics station list with nothing to draw.
            */
            $catalogue = array_values($metrics);

            if ($catalogue !== []) {
                $catalogue = array_merge($catalogue, self::LOGGER_METRICS);
            }

            foreach ($catalogue as $index => $metric) {
                SensorMetric::query()->updateOrCreate(
                    ['sensor_station_id' => $station->id, 'key' => $metric['key']],
                    $metric + ['sort_order' => $index],
                );
            }

            // A parameter the catalogue no longer lists is gone, along with
            // its readings — the same rule the hotspots follow below.
            SensorMetric::query()
                ->where('sensor_station_id', $station->id)
                ->whereNotIn('key', array_column($catalogue, 'key'))
                ->delete();

            $keptStations[] = $station->id;

            foreach (array_values($hotspots) as $index => $hotspot) {
                $this->hotspot($station, $hotspot, $index, $placed['hotspots'] ?? []);
            }
        }

        // Second pass: hotspots that jump to another panorama.
        $links = [
            'base-dam' => [['label' => 'Menuju Spillway', 'target' => 'awgc-01', 'yaw' => 118, 'pitch' => -6]],
            'awgc-01' => [['label' => 'Menuju Puncak Bendungan', 'target' => 'base-dam', 'yaw' => -96, 'pitch' => -4]],
            'awlr-hulu' => [['label' => 'Menuju Tubuh Bendungan', 'target' => 'base-dam', 'yaw' => 64, 'pitch' => -5]],
            'v-notch' => [['label' => 'Menuju AWLR Hilir', 'target' => 'awlr-hilir', 'yaw' => 142, 'pitch' => -8]],
            'awlr-hilir' => [['label' => 'Menuju V-Notch', 'target' => 'v-notch', 'yaw' => -38, 'pitch' => -8]],
            'adr-01' => [['label' => 'Menuju ADR-02', 'target' => 'adr-02', 'yaw' => 156, 'pitch' => -3]],
        ];

        foreach ($links as $code => $definitions) {
            $station = SensorStation::query()->where('code', $code)->first();

            foreach (array_values($definitions) as $index => $definition) {
                $target = SensorStation::query()->where('code', $definition['target'])->first();

                if (! $station || ! $target) {
                    continue;
                }

                // Links sort after the instruments, whatever the catalogue holds.
                $this->hotspot($station, [
                    'target_station_id' => $target->id,
                    'type' => 'link',
                    'label' => $definition['label'],
                    'yaw' => $definition['yaw'],
                    'pitch' => $definition['pitch'],
                ], 90 + $index, $this->placements[$code]['hotspots'] ?? []);
            }
        }

        /*
        | A station the catalogue no longer lists is gone too, and everything
        | hanging off it with it: readings, metrics and hotspots cascade, while
        | alerts and maintenance jobs keep their history with a null station.
        | Without this a renamed or retired point stayed on the stage for ever,
        | because nothing else ever deletes one.
        */
        SensorStation::query()
            ->where('dam_id', $dam->id)
            ->whereNotIn('id', $keptStations)
            ->delete();

        // Anything this run did not touch is no longer in the catalogue.
        foreach ($this->keptHotspots as $stationId => $ids) {
            PanoramaHotspot::query()
                ->where('sensor_station_id', $stationId)
                ->whereNotIn('id', $ids)
                ->delete();
        }
    }

    /**
     * Upsert one hotspot, matched on its label.
     *
     * The angles are written only when the row is created: a prism or an
     * instrument can be dragged onto its real spot from the stage, and a
     * re-seed must not throw that placement away. Everything else — label
     * copy, description, the metric it reads — is refreshed from the
     * catalogue, which is where those belong.
     */
    private function hotspot(SensorStation $station, array $hotspot, int $order, array $placed = []): void
    {
        // The exported placement wins over the catalogue's estimate, but only
        // as the value a *new* row starts at.
        $exported = $placed[$hotspot['label']] ?? [];
        $position = [
            'yaw' => $exported['yaw'] ?? $hotspot['yaw'],
            'pitch' => $exported['pitch'] ?? $hotspot['pitch'],
        ];
        unset($hotspot['yaw'], $hotspot['pitch']);

        $record = PanoramaHotspot::query()->firstOrNew([
            'sensor_station_id' => $station->id,
            'label' => $hotspot['label'],
        ]);

        // Where somebody nudged a single stake is placement work, the same as
        // the row's own angles, so the catalogue's `meta` must not carry it
        // away when the copy is refreshed.
        if (isset($hotspot['meta'])) {
            $places = $record->meta['places'] ?? ($record->exists ? null : $exported['places'] ?? null);

            if ($places) {
                $hotspot['meta']['places'] = $places;
            }
        }

        // Defaults as well as the values: a hotspot that used to carry a
        // description or a metric must not keep it once the catalogue drops it.
        $record->fill($hotspot + [
            'sort_order' => $order,
            'type' => 'metric',
            'description' => null,
            'metric_key' => null,
            'target_station_id' => null,
            'meta' => null,
        ]);

        if (! $record->exists) {
            $record->fill($position);
        }

        $record->save();

        $this->keptHotspots[$station->id][] = $record->id;
    }

    /** @return list<array<string, mixed>> */
    private function catalogue(): array
    {
        return [
            [
                'code' => 'base-dam',
                'name' => 'Ikhtisar Tubuh Bendungan',
                'short_name' => 'Base Dam',
                'type' => 'overview',
                'group' => 'ikhtisar',
                'zone' => 'Tubuh Bendungan',
                'latitude' => -1.9536,
                'longitude' => 119.3411,
                'elevation' => 100.500,
                'map_x' => 43.5,
                'map_y' => 40.5,
                'panorama' => 'base-dam',
                'panorama_yaw' => 0,
                'description' => 'Panorama udara tubuh bendungan, spillway, dan bangunan pengambilan sebagai titik orientasi utama digital twin.',
                'installed_on' => '2024-08-01',
                'hotspots' => [
                    ['type' => 'info', 'label' => 'Puncak Bendungan', 'description' => 'Elevasi puncak +100,50 m; panjang 415 m.', 'yaw' => -24, 'pitch' => -4],
                    ['type' => 'info', 'label' => 'Bangunan Pengambilan', 'description' => 'Intake tower dengan 3 tingkat bukaan.', 'yaw' => 74, 'pitch' => -10],
                ],
            ],
            [
                'code' => 'awlr-hulu',
                'name' => 'AWLR Hulu — Muka Air Waduk',
                'short_name' => 'AWLR Hulu',
                'type' => 'water_level',
                'zone' => 'Hulu / Intake',
                'latitude' => -1.9502,
                'longitude' => 119.3372,
                'elevation' => 95.200,
                'map_x' => 22.1,
                'map_y' => 59.8,
                'panorama' => 'awlr-hulu',
                'panorama_bearing' => 351,
                'vendor' => 'Beacon Engineering',
                'model' => 'BE-WLR-100-U150',
                'telemetry_channel' => 'AWLR-HULU',
                'installed_on' => '2024-09-12',
                'calibrated_on' => '2026-06-18',
                'description' => 'Radar muka air pada bangunan pengambilan; sumber utama elevasi waduk dan perhitungan inflow.',
                'metrics' => [
                    ['key' => 'water_level', 'label' => 'Muka Air Waduk', 'unit' => 'mdpl', 'decimals' => 3, 'is_primary' => true, 'chart_type' => 'area', 'normal_min' => 88.0, 'normal_max' => 95.5, 'warning_threshold' => 95.8, 'alert_threshold' => 96.6, 'critical_threshold' => 97.4],
                    ['key' => 'inflow', 'label' => 'Inflow (Qin)', 'unit' => 'm³/s', 'decimals' => 2, 'chart_type' => 'area', 'normal_min' => 0, 'normal_max' => 80, 'warning_threshold' => 90, 'alert_threshold' => 130, 'critical_threshold' => 180],
                    ['key' => 'storage_volume', 'label' => 'Volume Tampungan', 'unit' => 'juta m³', 'decimals' => 2, 'normal_min' => 20, 'normal_max' => 70],
                    ['key' => 'water_depth', 'label' => 'Kedalaman Air', 'unit' => 'm', 'decimals' => 2, 'normal_min' => 4, 'normal_max' => 14],
                    ['key' => 'raw_reading', 'label' => 'Bacaan Sensor Mentah', 'unit' => 'mm', 'decimals' => 0, 'normal_min' => 0, 'normal_max' => 15000],
                    ['key' => 'sensor_status', 'label' => 'Status Sensor', 'unit' => 'status', 'decimals' => 0, 'chart_type' => 'bar', 'states' => ['0' => 'Normal', '1' => 'Peringatan', '2' => 'Gangguan'], 'normal_min' => 0, 'normal_max' => 0, 'warning_threshold' => 1, 'alert_threshold' => 2],
                ],
                'hotspots' => [
                    ['type' => 'metric', 'label' => 'Sensor Radar AWLR', 'metric_key' => 'water_level', 'description' => 'Radar level 24 GHz, akurasi ±3 mm.', 'yaw' => 8, 'pitch' => -14],
                    ['type' => 'info', 'label' => 'Papan Duga Manual', 'description' => 'Pembanding harian pukul 07.00 dan 17.00 WITA.', 'yaw' => -68, 'pitch' => -18],
                ],
            ],
            [
                'code' => 'awlr-hilir',
                'name' => 'AWLR Hilir — Sungai Bawah Bendungan',
                'short_name' => 'AWLR Hilir',
                'type' => 'water_level',
                'zone' => 'Hilir',
                'latitude' => -1.9601,
                'longitude' => 119.3488,
                'elevation' => 27.400,
                'map_x' => 62.0,
                'map_y' => 78.0,
                'panorama' => 'awlr-hilir',
                'panorama_bearing' => 18,
                'vendor' => 'Beacon Engineering',
                'model' => 'BE-WLR-100-U150',
                'telemetry_channel' => 'AWLR-HILIR',
                'installed_on' => '2024-09-14',
                'description' => 'Pemantauan muka air dan debit sungai di hilir bendungan untuk verifikasi pelepasan air.',
                'metrics' => [
                    ['key' => 'water_level', 'label' => 'Muka Air Sungai', 'unit' => 'mdpl', 'decimals' => 3, 'is_primary' => true, 'chart_type' => 'area', 'normal_min' => 26.0, 'normal_max' => 29.5, 'warning_threshold' => 30.0, 'alert_threshold' => 31.0, 'critical_threshold' => 32.0],
                    ['key' => 'discharge', 'label' => 'Debit Sungai', 'unit' => 'm³/s', 'decimals' => 2, 'chart_type' => 'area', 'normal_min' => 0, 'normal_max' => 70, 'warning_threshold' => 90, 'alert_threshold' => 130],
                    ['key' => 'water_depth', 'label' => 'Kedalaman Air', 'unit' => 'm', 'decimals' => 2, 'normal_min' => 0.5, 'normal_max' => 4.5],
                    ['key' => 'raw_reading', 'label' => 'Bacaan Sensor Mentah', 'unit' => 'mm', 'decimals' => 0, 'normal_min' => 0, 'normal_max' => 6000],
                    ['key' => 'sensor_status', 'label' => 'Status Sensor', 'unit' => 'status', 'decimals' => 0, 'chart_type' => 'bar', 'states' => ['0' => 'Normal', '1' => 'Peringatan', '2' => 'Gangguan'], 'normal_min' => 0, 'normal_max' => 0, 'warning_threshold' => 1, 'alert_threshold' => 2],
                ],
                'hotspots' => [
                    ['type' => 'metric', 'label' => 'Stasiun AWLR Hilir', 'metric_key' => 'water_level', 'yaw' => 14, 'pitch' => -12],
                ],
            ],
            [
                'code' => 'awgc-01',
                'name' => 'AWGC — Kontrol Pintu Spillway',
                'short_name' => 'AWGC Spillway',
                'type' => 'gate',
                'group' => 'hidromekanikal',
                'zone' => 'Spillway',
                'latitude' => -1.9549,
                'longitude' => 119.3439,
                'elevation' => 96.000,
                'map_x' => 47.0,
                'map_y' => 52.0,
                'panorama' => 'awgc-01',
                'panorama_bearing' => 352,
                'vendor' => 'Beacon Engineering',
                'model' => 'BE-AWGC-3G',
                'telemetry_channel' => 'AWGC-01',
                'installed_on' => '2024-10-02',
                'description' => 'Monitoring bukaan pintu dan debit limpasan pelimpah, termasuk tinggi limpasan di atas ambang.',
                'metrics' => [
                    ['key' => 'discharge', 'label' => 'Outflow (Qout)', 'unit' => 'm³/s', 'decimals' => 2, 'is_primary' => true, 'chart_type' => 'area', 'normal_min' => 0, 'normal_max' => 70, 'warning_threshold' => 90, 'alert_threshold' => 130, 'critical_threshold' => 180],
                    ['key' => 'gate_opening', 'label' => 'Bukaan Pintu', 'unit' => 'cm', 'decimals' => 1, 'chart_type' => 'bar', 'normal_min' => 0, 'normal_max' => 100],
                    ['key' => 'head_over_crest', 'label' => 'Tinggi Limpasan', 'unit' => 'm', 'decimals' => 3, 'normal_min' => 0, 'normal_max' => 1.5, 'warning_threshold' => 2.0, 'alert_threshold' => 2.6],
                    ['key' => 'pool_level', 'label' => 'Tinggi Muka Air', 'unit' => 'mdpl', 'decimals' => 3, 'chart_type' => 'area', 'normal_min' => 88.0, 'normal_max' => 95.5, 'warning_threshold' => 95.8, 'alert_threshold' => 96.6],
                    ['key' => 'gate_target', 'label' => 'Target Pintu', 'unit' => 'cm', 'decimals' => 1, 'normal_min' => 0, 'normal_max' => 100],
                    ['key' => 'gate_opening_1', 'label' => 'Bukaan Pintu 1', 'unit' => 'cm', 'decimals' => 1, 'chart_type' => 'area', 'normal_min' => 0, 'normal_max' => 100],
                    ['key' => 'gate_opening_2', 'label' => 'Bukaan Pintu 2', 'unit' => 'cm', 'decimals' => 1, 'chart_type' => 'area', 'normal_min' => 0, 'normal_max' => 100],
                    ['key' => 'gate_opening_3', 'label' => 'Bukaan Pintu 3', 'unit' => 'cm', 'decimals' => 1, 'chart_type' => 'area', 'normal_min' => 0, 'normal_max' => 100],
                    ['key' => 'gate_target_1', 'label' => 'Target Pintu 1', 'unit' => 'cm', 'decimals' => 1, 'normal_min' => 0, 'normal_max' => 100],
                    ['key' => 'gate_target_2', 'label' => 'Target Pintu 2', 'unit' => 'cm', 'decimals' => 1, 'normal_min' => 0, 'normal_max' => 100],
                    ['key' => 'gate_target_3', 'label' => 'Target Pintu 3', 'unit' => 'cm', 'decimals' => 1, 'normal_min' => 0, 'normal_max' => 100],
                    ['key' => 'gate_current_r_1', 'label' => 'Arus R Pintu 1', 'unit' => 'A', 'decimals' => 2, 'normal_min' => 0, 'normal_max' => 12, 'warning_threshold' => 14, 'alert_threshold' => 18],
                    ['key' => 'gate_current_s_1', 'label' => 'Arus S Pintu 1', 'unit' => 'A', 'decimals' => 2, 'normal_min' => 0, 'normal_max' => 12, 'warning_threshold' => 14, 'alert_threshold' => 18],
                    ['key' => 'gate_current_t_1', 'label' => 'Arus T Pintu 1', 'unit' => 'A', 'decimals' => 2, 'normal_min' => 0, 'normal_max' => 12, 'warning_threshold' => 14, 'alert_threshold' => 18],
                    ['key' => 'gate_current_r_2', 'label' => 'Arus R Pintu 2', 'unit' => 'A', 'decimals' => 2, 'normal_min' => 0, 'normal_max' => 12, 'warning_threshold' => 14, 'alert_threshold' => 18],
                    ['key' => 'gate_current_s_2', 'label' => 'Arus S Pintu 2', 'unit' => 'A', 'decimals' => 2, 'normal_min' => 0, 'normal_max' => 12, 'warning_threshold' => 14, 'alert_threshold' => 18],
                    ['key' => 'gate_current_t_2', 'label' => 'Arus T Pintu 2', 'unit' => 'A', 'decimals' => 2, 'normal_min' => 0, 'normal_max' => 12, 'warning_threshold' => 14, 'alert_threshold' => 18],
                    ['key' => 'gate_current_r_3', 'label' => 'Arus R Pintu 3', 'unit' => 'A', 'decimals' => 2, 'normal_min' => 0, 'normal_max' => 12, 'warning_threshold' => 14, 'alert_threshold' => 18],
                    ['key' => 'gate_current_s_3', 'label' => 'Arus S Pintu 3', 'unit' => 'A', 'decimals' => 2, 'normal_min' => 0, 'normal_max' => 12, 'warning_threshold' => 14, 'alert_threshold' => 18],
                    ['key' => 'gate_current_t_3', 'label' => 'Arus T Pintu 3', 'unit' => 'A', 'decimals' => 2, 'normal_min' => 0, 'normal_max' => 12, 'warning_threshold' => 14, 'alert_threshold' => 18],
                    ['key' => 'motor_status', 'label' => 'Kondisi Motor', 'unit' => 'status', 'decimals' => 0, 'chart_type' => 'bar', 'states' => ['0' => 'Mati', '1' => 'Berjalan', '2' => 'Gangguan'], 'normal_min' => 0, 'normal_max' => 1, 'warning_threshold' => 2],
                    ['key' => 'gate_status', 'label' => 'Kondisi Pintu', 'unit' => 'status', 'decimals' => 0, 'chart_type' => 'bar', 'states' => ['0' => 'Tertutup', '1' => 'Bergerak', '2' => 'Terbuka'], 'normal_min' => 0, 'normal_max' => 2],
                ],
                'hotspots' => [
                    ['type' => 'metric', 'label' => 'Rumah Hoist Pintu', 'metric_key' => 'gate_opening', 'description' => 'Tiga pintu radial dengan aktuator hidrolik.', 'yaw' => -6, 'pitch' => -2],
                    /*
                    | One marker per leaf, on the bay it belongs to. The angles
                    | are an estimate off the render — the spillway is not
                    | surveyed — so they are meant to be dragged onto the real
                    | bays from the stage, which is what the placement control
                    | is for. `height_cm` is the stroke, and it is what turns a
                    | percentage into the figure an operator opens a gate by.
                    */
                    ['type' => 'gate', 'label' => 'Pintu 1', 'metric_key' => 'gate_opening_1', 'description' => 'Pintu radial kiri; aktuator hidrolik, langkah penuh 100 cm.', 'yaw' => -13, 'pitch' => -13, 'meta' => ['gate' => 1, 'height_cm' => 100, 'cell_yaw' => 5.4, 'cell_pitch' => 6.2]],
                    ['type' => 'gate', 'label' => 'Pintu 2', 'metric_key' => 'gate_opening_2', 'description' => 'Pintu radial tengah; aktuator hidrolik, langkah penuh 100 cm.', 'yaw' => -5, 'pitch' => -13, 'meta' => ['gate' => 2, 'height_cm' => 100, 'cell_yaw' => 5.4, 'cell_pitch' => 6.2]],
                    ['type' => 'gate', 'label' => 'Pintu 3', 'metric_key' => 'gate_opening_3', 'description' => 'Pintu radial kanan; aktuator hidrolik, langkah penuh 100 cm.', 'yaw' => 3, 'pitch' => -13, 'meta' => ['gate' => 3, 'height_cm' => 100, 'cell_yaw' => 5.4, 'cell_pitch' => 6.2]],
                    ['type' => 'info', 'label' => 'Saluran Peluncur', 'description' => 'Chute beton dengan stilling basin di ujung hilir.', 'yaw' => 42, 'pitch' => -22],
                ],
            ],
            [
                'code' => 'awqr-bendungan',
                'name' => 'AWQR Bendungan',
                'short_name' => 'AWQR Bendungan',
                'type' => 'water_quality',
                'group' => 'hidrologi',
                'zone' => 'Inlet Waduk',
                'latitude' => -1.9418,
                'longitude' => 119.3521,
                'elevation' => 27.360,
                'map_x' => 79.3,
                'map_y' => 22.7,
                'panorama' => 'awlr-awqr-sedimen',
                'panorama_bearing' => 44,
                'vendor' => 'Beacon Engineering',
                'model' => 'BE-AWQR-5P',
                'telemetry_channel' => 'AWQR-01',
                'installed_on' => '2024-11-08',
                'description' => 'Sonde multiparameter kualitas air pada inlet waduk.',
                'metrics' => [
                    ['key' => 'turbidity', 'label' => 'Kekeruhan', 'unit' => 'NTU', 'decimals' => 1, 'is_primary' => true, 'chart_type' => 'area', 'normal_min' => 0, 'normal_max' => 25, 'warning_threshold' => 40, 'alert_threshold' => 80, 'critical_threshold' => 150],
                    ['key' => 'ph', 'label' => 'pH', 'unit' => '', 'decimals' => 2, 'normal_min' => 6.5, 'normal_max' => 8.5],
                    ['key' => 'dissolved_oxygen', 'label' => 'Oksigen Terlarut', 'unit' => 'mg/L', 'decimals' => 2, 'normal_min' => 5, 'normal_max' => 9],
                ],
                'hotspots' => [
                    ['type' => 'metric', 'label' => 'Sonde Multiparameter', 'metric_key' => 'turbidity', 'yaw' => -12, 'pitch' => -16],
                ],
            ],
            /*
            | The three instruments on the inlet used to be one record called
            | "AWLR, AWQR & Sedimen". They are three loggers on one structure,
            | so they are three stations that happen to share a panorama — a
            | reader opening "Sedimen Bendungan" should get sediment, not a
            | station named after two other things as well.
            */
            [
                'code' => 'awlr-bendungan',
                'name' => 'AWLR Bendungan',
                'short_name' => 'AWLR Bendungan',
                'type' => 'water_level',
                'group' => 'hidrologi',
                'zone' => 'Inlet Waduk',
                'latitude' => -1.9420,
                'longitude' => 119.3518,
                'elevation' => 27.360,
                'map_x' => 76.5,
                'map_y' => 24.1,
                'panorama' => 'awlr-awqr-sedimen',
                'panorama_bearing' => 44,
                'vendor' => 'Beacon Engineering',
                'model' => 'BE-WLR-100-U150',
                'telemetry_channel' => 'AWLR-BDG',
                'installed_on' => '2024-11-08',
                'description' => 'Radar muka air pada inlet waduk, pasangan AWQR dan sampler sedimen.',
                'metrics' => [
                    ['key' => 'water_level', 'label' => 'Muka Air Sungai', 'unit' => 'mdpl', 'decimals' => 3, 'is_primary' => true, 'chart_type' => 'area', 'normal_min' => 24, 'normal_max' => 30.5, 'warning_threshold' => 31.2, 'alert_threshold' => 32.4],
                    ['key' => 'water_depth', 'label' => 'Kedalaman Air', 'unit' => 'm', 'decimals' => 2, 'normal_min' => 0.5, 'normal_max' => 6],
                    ['key' => 'raw_reading', 'label' => 'Bacaan Sensor Mentah', 'unit' => 'mm', 'decimals' => 0, 'normal_min' => 0, 'normal_max' => 15000],
                ],
                'hotspots' => [
                    ['type' => 'metric', 'label' => 'Radar Muka Air', 'metric_key' => 'water_level', 'yaw' => 26, 'pitch' => -15],
                ],
            ],
            [
                'code' => 'sedimen-bendungan',
                'name' => 'Sedimen Bendungan',
                'short_name' => 'Sedimen Bendungan',
                'type' => 'sediment',
                'group' => 'hidrologi',
                'zone' => 'Inlet Waduk',
                'latitude' => -1.9416,
                'longitude' => 119.3524,
                'elevation' => 27.360,
                'map_x' => 82.1,
                'map_y' => 24.6,
                'panorama' => 'awlr-awqr-sedimen',
                'panorama_bearing' => 44,
                'vendor' => 'Beacon Engineering',
                'model' => 'BE-SED-2A',
                'telemetry_channel' => 'SED-BDG',
                'installed_on' => '2024-11-08',
                'description' => 'Sampler muatan sedimen pada inlet waduk.',
                'metrics' => [
                    ['key' => 'sediment_load', 'label' => 'Muatan Sedimen', 'unit' => 'mg/L', 'decimals' => 1, 'is_primary' => true, 'chart_type' => 'bar', 'normal_min' => 0, 'normal_max' => 150, 'warning_threshold' => 220, 'alert_threshold' => 350],
                    ['key' => 'turbidity', 'label' => 'Kekeruhan', 'unit' => 'NTU', 'decimals' => 1, 'chart_type' => 'area', 'normal_min' => 0, 'normal_max' => 25, 'warning_threshold' => 40, 'alert_threshold' => 80],
                ],
                'hotspots' => [
                    ['type' => 'metric', 'label' => 'Sampler Sedimen', 'metric_key' => 'sediment_load', 'yaw' => 96, 'pitch' => -14],
                ],
            ],
            [
                'code' => 'awr-01',
                'name' => 'AWR',
                'short_name' => 'AWR',
                'type' => 'weather',
                'group' => 'hidrologi',
                'zone' => 'Kantor OP',
                'latitude' => -1.9558,
                'longitude' => 119.3356,
                'elevation' => 92.800,
                'map_x' => 27.9,
                'map_y' => 36.5,
                'panorama' => 'awr-01',
                'panorama_bearing' => 340,
                'vendor' => 'Beacon Engineering',
                'model' => 'BE-AWS-6S',
                'telemetry_channel' => 'AWR-01',
                'installed_on' => '2024-09-20',
                'calibrated_on' => '2026-07-04',
                'description' => 'Stasiun cuaca lengkap: hujan, suhu, kelembapan, angin, dan radiasi matahari. Menjadi sumber strip cuaca pada header dashboard.',
                'metrics' => [
                    ['key' => 'rainfall_24h', 'label' => 'Curah Hujan 24 Jam', 'unit' => 'mm', 'decimals' => 1, 'is_primary' => true, 'chart_type' => 'bar', 'normal_min' => 0, 'normal_max' => 50, 'warning_threshold' => 50, 'alert_threshold' => 100, 'critical_threshold' => 150],
                    ['key' => 'rainfall_intensity', 'label' => 'Intensitas Hujan', 'unit' => 'mm/jam', 'decimals' => 1, 'chart_type' => 'bar', 'normal_min' => 0, 'normal_max' => 20, 'warning_threshold' => 30, 'alert_threshold' => 50],
                    ['key' => 'temperature', 'label' => 'Suhu Udara', 'unit' => '°C', 'decimals' => 1, 'normal_min' => 18, 'normal_max' => 36],
                    ['key' => 'humidity', 'label' => 'Kelembapan', 'unit' => '%', 'decimals' => 0, 'normal_min' => 40, 'normal_max' => 99],
                    ['key' => 'wind_speed', 'label' => 'Kecepatan Angin', 'unit' => 'm/s', 'decimals' => 1, 'normal_min' => 0, 'normal_max' => 12, 'warning_threshold' => 15],
                    ['key' => 'solar_radiation', 'label' => 'Radiasi Matahari', 'unit' => 'W/m²', 'decimals' => 0, 'normal_min' => 0, 'normal_max' => 1100],
                    ['key' => 'wind_direction', 'label' => 'Arah Angin', 'unit' => '°', 'decimals' => 0, 'normal_min' => 0, 'normal_max' => 360],
                    ['key' => 'air_pressure', 'label' => 'Tekanan Udara', 'unit' => 'mbar', 'decimals' => 1, 'normal_min' => 1000, 'normal_max' => 1018],
                    ['key' => 'illuminance', 'label' => 'Iluminasi', 'unit' => 'lux', 'decimals' => 0, 'chart_type' => 'area', 'normal_min' => 0, 'normal_max' => 110000],
                ],
                'hotspots' => [
                    ['type' => 'metric', 'label' => 'Anemometer & Wind Vane', 'metric_key' => 'wind_speed', 'yaw' => 4, 'pitch' => 6],
                    ['type' => 'metric', 'label' => 'Penakar Hujan Tipping Bucket', 'metric_key' => 'rainfall_24h', 'yaw' => -18, 'pitch' => -10],
                    ['type' => 'info', 'label' => 'Panel Surya & Baterai', 'description' => 'Suplai 100 Wp dengan cadangan 3 hari.', 'yaw' => 24, 'pitch' => -4],
                ],
            ],
            [
                'code' => 'adr-01',
                'name' => 'ADR-01 — Robotic Total Station Kanan',
                'short_name' => 'ADR-01 (RTS)',
                'type' => 'deformation',
                'group' => 'geoteknik',
                'zone' => 'Bukit Tumpuan Kanan',
                'latitude' => -1.9521,
                'longitude' => 119.3462,
                'elevation' => 108.400,
                'map_x' => 82.6,
                'map_y' => 37.3,
                'panorama' => 'adr-01',
                'panorama_bearing' => 356,
                'vendor' => 'Leica Geosystems',
                'model' => 'TM50 + BE-ADR Controller',
                'telemetry_channel' => 'ADR-01',
                'installed_on' => '2025-01-16',
                'calibrated_on' => '2026-08-02',
                'description' => 'Robotic total station di rumah pengamatan; mengukur prisma pada puncak dan lereng hilir secara otomatis tiap 6 jam.',
                'metrics' => [
                    ['key' => 'displacement_h', 'label' => 'Pergeseran Horizontal', 'unit' => 'mm', 'decimals' => 2, 'is_primary' => true, 'normal_min' => -8, 'normal_max' => 8, 'warning_threshold' => 10, 'alert_threshold' => 15, 'critical_threshold' => 25],
                    ['key' => 'displacement_v', 'label' => 'Pergeseran Vertikal', 'unit' => 'mm', 'decimals' => 2, 'normal_min' => -8, 'normal_max' => 8, 'warning_threshold' => 10, 'alert_threshold' => 15],
                    ['key' => 'prisms_measured', 'label' => 'Prisma Terukur', 'unit' => 'titik', 'decimals' => 0, 'chart_type' => 'bar', 'normal_min' => 38, 'normal_max' => 48],
                    ['key' => 'cycle_duration', 'label' => 'Durasi Siklus', 'unit' => 'menit', 'decimals' => 1, 'normal_min' => 5, 'normal_max' => 20],
                    ['key' => 'distance', 'label' => 'Jarak ke Prisma', 'unit' => 'm', 'decimals' => 3, 'normal_min' => 120, 'normal_max' => 480],
                    ['key' => 'angle_h', 'label' => 'Sudut Horizontal', 'unit' => '°', 'decimals' => 4, 'normal_min' => 0, 'normal_max' => 360],
                    ['key' => 'angle_v', 'label' => 'Sudut Vertikal', 'unit' => '°', 'decimals' => 4, 'normal_min' => 60, 'normal_max' => 120],
                    ['key' => 'coord_e', 'label' => 'Koordinat Easting', 'unit' => 'm', 'decimals' => 3, 'normal_min' => 700000, 'normal_max' => 720000],
                    ['key' => 'coord_n', 'label' => 'Koordinat Northing', 'unit' => 'm', 'decimals' => 3, 'normal_min' => 9760000, 'normal_max' => 9790000],
                    ['key' => 'coord_h', 'label' => 'Koordinat Elevasi', 'unit' => 'm', 'decimals' => 3, 'normal_min' => 80, 'normal_max' => 130],
                ],
                'hotspots' => [
                    ['type' => 'metric', 'label' => 'Total Station', 'metric_key' => 'displacement_h', 'description' => 'Presisi sudut 0,5"; jangkauan prisma 3.500 m.', 'yaw' => 2, 'pitch' => -8],
                    ['type' => 'info', 'label' => 'Panel Kontrol & UPS', 'description' => 'Controller ADR dengan modem 4G dan UPS 2 jam.', 'yaw' => 86, 'pitch' => -6],
                ],
            ],
            [
                'code' => 'adr-02',
                'name' => 'ADR-02 — Robotic Total Station Kiri',
                'short_name' => 'ADR-02 (RTS)',
                'type' => 'deformation',
                'group' => 'geoteknik',
                'zone' => 'Bukit Tumpuan Kiri',
                'latitude' => -1.9563,
                'longitude' => 119.3384,
                'elevation' => 106.900,
                'map_x' => 28.0,
                'map_y' => 27.0,
                'panorama' => 'adr-02',
                'panorama_bearing' => 0,
                'vendor' => 'Leica Geosystems',
                'model' => 'TM50 + BE-ADR Controller',
                'telemetry_channel' => 'ADR-02',
                'installed_on' => '2025-01-18',
                'calibrated_on' => '2026-08-02',
                'description' => 'Pasangan RTS di tumpuan kiri untuk pengukuran silang prisma deformasi tubuh bendungan.',
                'metrics' => [
                    ['key' => 'displacement_h', 'label' => 'Pergeseran Horizontal', 'unit' => 'mm', 'decimals' => 2, 'is_primary' => true, 'normal_min' => -8, 'normal_max' => 8, 'warning_threshold' => 10, 'alert_threshold' => 15, 'critical_threshold' => 25],
                    ['key' => 'displacement_v', 'label' => 'Pergeseran Vertikal', 'unit' => 'mm', 'decimals' => 2, 'normal_min' => -8, 'normal_max' => 8, 'warning_threshold' => 1.9, 'alert_threshold' => 2.4, 'critical_threshold' => 12],
                    ['key' => 'prisms_measured', 'label' => 'Prisma Terukur', 'unit' => 'titik', 'decimals' => 0, 'chart_type' => 'bar', 'normal_min' => 38, 'normal_max' => 48],
                    ['key' => 'cycle_duration', 'label' => 'Durasi Siklus', 'unit' => 'menit', 'decimals' => 1, 'normal_min' => 5, 'normal_max' => 20],
                    ['key' => 'distance', 'label' => 'Jarak ke Prisma', 'unit' => 'm', 'decimals' => 3, 'normal_min' => 120, 'normal_max' => 480],
                    ['key' => 'angle_h', 'label' => 'Sudut Horizontal', 'unit' => '°', 'decimals' => 4, 'normal_min' => 0, 'normal_max' => 360],
                    ['key' => 'angle_v', 'label' => 'Sudut Vertikal', 'unit' => '°', 'decimals' => 4, 'normal_min' => 60, 'normal_max' => 120],
                    ['key' => 'coord_e', 'label' => 'Koordinat Easting', 'unit' => 'm', 'decimals' => 3, 'normal_min' => 700000, 'normal_max' => 720000],
                    ['key' => 'coord_n', 'label' => 'Koordinat Northing', 'unit' => 'm', 'decimals' => 3, 'normal_min' => 9760000, 'normal_max' => 9790000],
                    ['key' => 'coord_h', 'label' => 'Koordinat Elevasi', 'unit' => 'm', 'decimals' => 3, 'normal_min' => 80, 'normal_max' => 130],
                ],
                'hotspots' => [
                    ['type' => 'metric', 'label' => 'Total Station', 'metric_key' => 'displacement_h', 'yaw' => -4, 'pitch' => -8],
                    ['type' => 'plot', 'label' => 'Petak Hulu 1', 'description' => 'Petak pantau hulu, berm atas. Lima patok geser berprisma, masing-masing di tengah petaknya; jarak bidik 128 m, elevasi +98,20 m.', 'yaw' => -10.5, 'pitch' => -9.0, 'meta' => ['side' => 'hulu', 'code' => 'PG-HU1', 'stakes' => 5, 'stake_gap' => 1.8, 'cell_yaw' => 2.2, 'cell_pitch' => 1.4, 'line' => -84, 'foreshorten' => 0.78, 'aspect' => 6]],
                    ['type' => 'plot', 'label' => 'Petak Hulu 2', 'description' => 'Petak pantau hulu, berm tengah. Lima patok geser berprisma, masing-masing di tengah petaknya; jarak bidik 164 m, elevasi +94,60 m.', 'yaw' => -15.0, 'pitch' => -12.0, 'meta' => ['side' => 'hulu', 'code' => 'PG-HU2', 'stakes' => 5, 'stake_gap' => 1.8, 'cell_yaw' => 2.2, 'cell_pitch' => 1.4, 'line' => -86, 'foreshorten' => 0.78, 'aspect' => 8]],
                    ['type' => 'plot', 'label' => 'Petak Hulu 3', 'description' => 'Petak pantau hulu, dekat garis muka air. Lima patok geser berprisma, masing-masing di tengah petaknya; jarak bidik 212 m, elevasi +90,80 m.', 'yaw' => -19.5, 'pitch' => -15.5, 'meta' => ['side' => 'hulu', 'code' => 'PG-HU3', 'stakes' => 5, 'stake_gap' => 1.8, 'cell_yaw' => 2.2, 'cell_pitch' => 1.4, 'line' => -88, 'foreshorten' => 0.78, 'aspect' => 10]],
                    ['type' => 'plot', 'label' => 'Petak Hilir 1', 'description' => 'Petak pantau hilir, berm atas. Lima patok geser berprisma, masing-masing di tengah petaknya; jarak bidik 134 m, elevasi +97,50 m.', 'yaw' => 9.0, 'pitch' => -8.5, 'meta' => ['side' => 'hilir', 'code' => 'PG-HI1', 'stakes' => 5, 'stake_gap' => 1.8, 'cell_yaw' => 2.2, 'cell_pitch' => 1.4, 'line' => -96, 'foreshorten' => 0.78, 'aspect' => 10]],
                    ['type' => 'plot', 'label' => 'Petak Hilir 2', 'description' => 'Petak pantau hilir, berm tengah. Lima patok geser berprisma, masing-masing di tengah petaknya; jarak bidik 178 m, elevasi +93,10 m.', 'yaw' => 13.5, 'pitch' => -11.5, 'meta' => ['side' => 'hilir', 'code' => 'PG-HI2', 'stakes' => 5, 'stake_gap' => 1.8, 'cell_yaw' => 2.2, 'cell_pitch' => 1.4, 'line' => -94, 'foreshorten' => 0.78, 'aspect' => 12]],
                    ['type' => 'plot', 'label' => 'Petak Hilir 3', 'description' => 'Petak pantau hilir, kaki lereng. Lima patok geser berprisma, masing-masing di tengah petaknya; jarak bidik 226 m, elevasi +88,40 m.', 'yaw' => 18.0, 'pitch' => -15.0, 'meta' => ['side' => 'hilir', 'code' => 'PG-HI3', 'stakes' => 5, 'stake_gap' => 1.8, 'cell_yaw' => 2.2, 'cell_pitch' => 1.4, 'line' => -92, 'foreshorten' => 0.78, 'aspect' => 14]],
                ],
            ],
            [
                'code' => 'avwr-01',
                'name' => 'AVWR — Piezometer Vibrating Wire',
                'short_name' => 'AVWR Piezometer',
                'type' => 'piezometer',
                'group' => 'geoteknik',
                'zone' => 'Inti Bendungan',
                'latitude' => -1.9541,
                'longitude' => 119.3421,
                'elevation' => 88.600,
                'map_x' => 59.0,
                'map_y' => 48.0,
                'panorama' => 'avwr-01',
                'panorama_bearing' => 11,
                'vendor' => 'Geokon',
                'model' => '4500S + BE-AVWR Logger',
                'telemetry_channel' => 'AVWR-01',
                'installed_on' => '2024-12-05',
                'calibrated_on' => '2026-05-22',
                'description' => 'Rangkaian piezometer vibrating wire pada inti dan filter; indikator utama tekanan air pori tubuh bendungan.',
                'metrics' => [
                    ['key' => 'pore_pressure', 'label' => 'Tekanan Air Pori', 'unit' => 'kPa', 'decimals' => 1, 'is_primary' => true, 'chart_type' => 'area', 'normal_min' => 150, 'normal_max' => 280, 'warning_threshold' => 262, 'alert_threshold' => 330, 'critical_threshold' => 380],
                    ['key' => 'piezo_level', 'label' => 'Tinggi Piezometrik', 'unit' => 'mdpl', 'decimals' => 2, 'normal_min' => 84, 'normal_max' => 91],
                    ['key' => 'water_column', 'label' => 'Kolom Air', 'unit' => 'mH2O', 'decimals' => 2, 'normal_min' => 8, 'normal_max' => 16],
                    ['key' => 'battery_voltage', 'label' => 'Tegangan Baterai', 'unit' => 'V', 'decimals' => 2, 'normal_min' => 11.8, 'normal_max' => 14.5],
                    ['key' => 'frequency_raw', 'label' => 'Frekuensi Mentah', 'unit' => 'B-unit', 'decimals' => 1, 'normal_min' => 7800, 'normal_max' => 9200],
                    ['key' => 'sensor_temperature', 'label' => 'Suhu Sensor', 'unit' => '°C', 'decimals' => 2, 'normal_min' => 22, 'normal_max' => 32],
                ],
                'hotspots' => [
                    ['type' => 'metric', 'label' => 'Terminal Box Piezometer', 'metric_key' => 'pore_pressure', 'description' => '8 kanal vibrating wire pada dua elevasi.', 'yaw' => 0, 'pitch' => -18],
                    /*
                    | The one marker that opens a drawing instead of a
                    | panorama. A piezometer is buried, so there is nothing of
                    | it to stand on in a photograph; what the reader needs is
                    | the section it sits in, and it is asked for from the
                    | panorama that looks at the body those instruments are in.
                    | The figures below are an estimate off the crest elevation
                    | and the design phreatic line, the same standing the
                    | seeded marker angles have — replace them with the real
                    | section when the drawing is to hand, and drag the marker
                    | onto the axis it cuts.
                    */
                    [
                        'type' => 'piezo',
                        'label' => 'Potongan As Bendungan',
                        'description' => 'Piezometer pondasi dan timbunan pada as bendungan.',
                        'yaw' => 19,
                        'pitch' => -13,
                        'meta' => [
                            // Where the phreatic level is read from.
                            'station' => 'avwr-01',
                            'crest' => 100.5,
                            'crest_width' => 10.0,
                            'foundation' => 62.0,
                            'slope_up' => 2.75,     // horizontal per vertical
                            'slope_down' => 2.25,
                            'core_top' => 99.0,
                            'core_top_width' => 5.0,
                            'core_base_width' => 24.0,
                            'water' => 93.6,        // reservoir, for the picture
                            // What the design expects to stand at the axis,
                            // and how much head it sheds per metre downstream.
                            'design_phreatic' => 92.0,
                            'gradient' => 0.16,
                            /*
                            | Listed, not counted: every instrument sits at its
                            | own elevation and its own distance from the axis.
                            | `offset` is metres from the axis, positive
                            | downstream.
                            */
                            'points' => [
                                ['code' => 'PP1', 'kind' => 'pondasi', 'elevation' => 64.5, 'offset' => -28.0],
                                ['code' => 'PP2', 'kind' => 'pondasi', 'elevation' => 63.8, 'offset' => -12.0],
                                ['code' => 'PP3', 'kind' => 'pondasi', 'elevation' => 63.2, 'offset' => 6.0],
                                ['code' => 'PP4', 'kind' => 'pondasi', 'elevation' => 63.6, 'offset' => 26.0],
                                ['code' => 'PT1', 'kind' => 'timbunan', 'elevation' => 70.0, 'offset' => -18.0],
                                ['code' => 'PT2', 'kind' => 'timbunan', 'elevation' => 70.0, 'offset' => 8.0],
                                ['code' => 'PT3', 'kind' => 'timbunan', 'elevation' => 78.0, 'offset' => -13.0],
                                ['code' => 'PT4', 'kind' => 'timbunan', 'elevation' => 78.0, 'offset' => 5.0],
                                ['code' => 'PT5', 'kind' => 'timbunan', 'elevation' => 86.0, 'offset' => -8.0],
                                ['code' => 'PT6', 'kind' => 'timbunan', 'elevation' => 86.0, 'offset' => 3.0],
                                ['code' => 'PT7', 'kind' => 'timbunan', 'elevation' => 93.0, 'offset' => -3.0],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'code' => 'osp-ow',
                'name' => 'OSP & OW — Open Standpipe dan Sumur Pantau',
                'short_name' => 'OSP / OW',
                'type' => 'observation_well',
                'group' => 'geoteknik',
                'zone' => 'Lereng Hilir',
                'latitude' => -1.9572,
                'longitude' => 119.3402,
                'elevation' => 85.100,
                'map_x' => 33.5,
                'map_y' => 53.0,
                'panorama' => 'osp-ow',
                'panorama_bearing' => 350,
                'vendor' => 'Beacon Engineering',
                'model' => 'BE-OSP Logger',
                'telemetry_channel' => 'OSP-OW-01',
                'installed_on' => '2025-02-11',
                'description' => 'Piezometer pipa tegak dan sumur pantau pada lereng hilir untuk memverifikasi garis rembesan.',
                'metrics' => [
                    ['key' => 'well_level', 'label' => 'Muka Air Sumur Pantau', 'unit' => 'mdpl', 'decimals' => 2, 'is_primary' => true, 'chart_type' => 'area', 'normal_min' => 84.9, 'normal_max' => 88.0, 'warning_threshold' => 88.6, 'alert_threshold' => 89.5],
                    ['key' => 'water_column', 'label' => 'Kolom Air', 'unit' => 'm', 'decimals' => 2, 'normal_min' => 6, 'normal_max' => 16],
                ],
                'hotspots' => [
                    ['type' => 'metric', 'label' => 'Kepala Sumur Pantau', 'metric_key' => 'well_level', 'yaw' => 10, 'pitch' => -22],
                ],
            ],
            [
                'code' => 'v-notch',
                'name' => 'V-Notch — Ukur Rembesan Hilir',
                'short_name' => 'V-Notch Rembesan',
                'type' => 'seepage',
                'group' => 'geoteknik',
                'zone' => 'Kaki Hilir',
                'latitude' => -1.9587,
                'longitude' => 119.3444,
                'elevation' => 45.300,
                'map_x' => 64.2,
                'map_y' => 65.9,
                'panorama' => 'v-notch',
                'panorama_bearing' => 335,
                'vendor' => 'Beacon Engineering',
                'model' => 'BE-VN-90',
                'telemetry_channel' => 'VNOTCH-01',
                'installed_on' => '2024-12-19',
                'description' => 'Ambang ukur V-notch 90° pada saluran drainase kaki hilir; debit rembesan dan kekeruhannya dipantau menerus.',
                'metrics' => [
                    ['key' => 'seepage_flow', 'label' => 'Debit Rembesan', 'unit' => 'l/detik', 'decimals' => 2, 'is_primary' => true, 'chart_type' => 'area', 'normal_min' => 0, 'normal_max' => 22, 'warning_threshold' => 14.5, 'alert_threshold' => 32, 'critical_threshold' => 45],
                    ['key' => 'notch_head', 'label' => 'Tinggi Muka V-Notch', 'unit' => 'm', 'decimals' => 3, 'normal_min' => 0, 'normal_max' => 0.28],
                    ['key' => 'seepage_turbidity', 'label' => 'Kekeruhan Rembesan', 'unit' => 'NTU', 'decimals' => 1, 'normal_min' => 0, 'normal_max' => 12, 'warning_threshold' => 18, 'alert_threshold' => 30],
                ],
                'hotspots' => [
                    ['type' => 'metric', 'label' => 'Ambang V-Notch', 'metric_key' => 'seepage_flow', 'description' => 'Sudut 90°, kalibrasi debit sesuai SNI 8065.', 'yaw' => -8, 'pitch' => -24],
                    ['type' => 'metric', 'label' => 'Sensor Kekeruhan', 'metric_key' => 'seepage_turbidity', 'yaw' => 62, 'pitch' => -20],
                ],
            ],
            [
                'code' => 'gnss-tilt',
                'name' => 'GNSS & Tiltmeter Lereng',
                'short_name' => 'GNSS + Tilt',
                'type' => 'gnss',
                'group' => 'geoteknik',
                'zone' => 'Lereng Hilir Kanan',
                'latitude' => -1.9556,
                'longitude' => 119.3471,
                'elevation' => 97.700,
                'map_x' => 82.6,
                'map_y' => 57.4,
                'panorama' => 'gnss-tilt',
                'panorama_bearing' => 348,
                'vendor' => 'Beacon Engineering',
                'model' => 'BE-GNSS-RTK + BE-TILT-2A',
                'telemetry_channel' => 'GNSS-TILT-01',
                'installed_on' => '2025-03-04',
                'calibrated_on' => '2026-08-14',
                'description' => 'Pilar GNSS RTK berpasangan dengan tiltmeter biaxial; keluaran menjadi acuan deformasi harian pada panel ringkasan.',
                'metrics' => [
                    ['key' => 'displacement_h', 'label' => 'Deformasi Horizontal', 'unit' => 'mm', 'decimals' => 2, 'is_primary' => true, 'normal_min' => -6, 'normal_max' => 6, 'warning_threshold' => 8, 'alert_threshold' => 12, 'critical_threshold' => 20],
                    ['key' => 'displacement_v', 'label' => 'Deformasi Vertikal', 'unit' => 'mm', 'decimals' => 2, 'normal_min' => -6, 'normal_max' => 6, 'warning_threshold' => 8, 'alert_threshold' => 12],
                    ['key' => 'tilt', 'label' => 'Kemiringan Lereng', 'unit' => '°', 'decimals' => 3, 'normal_min' => 0, 'normal_max' => 0.15, 'warning_threshold' => 0.2, 'alert_threshold' => 0.35],
                    ['key' => 'battery_voltage', 'label' => 'Tegangan Baterai', 'unit' => 'V', 'decimals' => 2, 'normal_min' => 11.8, 'normal_max' => 14.5],
                ],
                'hotspots' => [
                    ['type' => 'metric', 'label' => 'Antena GNSS', 'metric_key' => 'displacement_h', 'description' => 'Solusi RTK 1 Hz, baseline ke base station kantor OP.', 'yaw' => 0, 'pitch' => 10],
                    ['type' => 'metric', 'label' => 'Tiltmeter Biaxial', 'metric_key' => 'tilt', 'yaw' => 34, 'pitch' => -20],
                ],
            ],
            [
                'code' => 'cctv-01',
                'name' => 'CCTV Bendungan',
                'short_name' => 'CCTV Bendungan',
                'type' => 'cctv',
                'group' => 'pengawasan',
                'zone' => 'Puncak',
                'latitude' => -1.9532,
                'longitude' => 119.3408,
                'elevation' => 101.200,
                'map_x' => 51.2,
                'map_y' => 15.4,
                'panorama' => 'cctv-01',
                'panorama_bearing' => 292,
                'vendor' => 'Hikvision',
                'model' => 'DS-2DE7A425IW-AEB',
                'telemetry_channel' => 'CCTV-01',
                'installed_on' => '2025-04-22',
                'description' => 'Kamera PTZ 25x pada puncak bendungan; menyorot jalan puncak, parapet, dan area intake.',
                'metrics' => [
                    ['key' => 'stream_bitrate', 'label' => 'Bitrate Stream', 'unit' => 'Mbps', 'decimals' => 2, 'is_primary' => true, 'normal_min' => 1, 'normal_max' => 8],
                    ['key' => 'frame_rate', 'label' => 'Frame Rate', 'unit' => 'fps', 'decimals' => 0, 'normal_min' => 15, 'normal_max' => 30],
                    ['key' => 'link_uptime', 'label' => 'Uptime Perangkat', 'unit' => '%', 'decimals' => 1, 'normal_min' => 95, 'normal_max' => 100],
                ],
                'hotspots' => [
                    ['type' => 'info', 'label' => 'Kamera PTZ', 'description' => 'Preset patroli 8 titik, IR 200 m.', 'yaw' => 0, 'pitch' => 8],
                ],
            ],
            [
                'code' => 'ews-01',
                'name' => 'EWS — Sirene Peringatan Hilir',
                'short_name' => 'EWS Hilir',
                'type' => 'ews',
                'group' => 'peringatan',
                'zone' => 'Desa Hilir',
                'latitude' => -1.9634,
                'longitude' => 119.3512,
                'elevation' => 32.100,
                'map_x' => 88.0,
                'map_y' => 74.0,
                'panorama' => 'ews-01',
                'panorama_bearing' => 330,
                'vendor' => 'Beacon Engineering',
                'model' => 'BE-EWS-120',
                'telemetry_channel' => 'EWS-01',
                'installed_on' => '2025-05-30',
                'description' => 'Sirene peringatan dini 120 dB dengan pengeras suara arah desa hilir; diuji otomatis setiap Jumat pukul 10.00 WITA.',
                'metrics' => [
                    ['key' => 'siren_battery', 'label' => 'Baterai Sirene', 'unit' => '%', 'decimals' => 0, 'is_primary' => true, 'normal_min' => 60, 'normal_max' => 100],
                    ['key' => 'siren_range', 'label' => 'Jangkauan Suara', 'unit' => 'km', 'decimals' => 2, 'normal_min' => 1.2, 'normal_max' => 3.0],
                    ['key' => 'signal_strength', 'label' => 'Kuat Sinyal', 'unit' => 'dBm', 'decimals' => 0, 'normal_min' => -95, 'normal_max' => -45],
                    ['key' => 'source_value', 'label' => 'Nilai Sumber (AWLR Hilir)', 'unit' => 'mdpl', 'decimals' => 3, 'chart_type' => 'area', 'normal_min' => 26.0, 'normal_max' => 29.5, 'warning_threshold' => 30.0, 'alert_threshold' => 31.0],
                    ['key' => 'warning_level', 'label' => 'Batas Peringatan', 'unit' => 'mdpl', 'decimals' => 2, 'normal_min' => 29, 'normal_max' => 31],
                    ['key' => 'alarm_level', 'label' => 'Level Alarm', 'unit' => 'level', 'decimals' => 0, 'chart_type' => 'bar', 'normal_min' => 0, 'normal_max' => 2, 'warning_threshold' => 3, 'alert_threshold' => 5, 'critical_threshold' => 7],
                    ['key' => 'siren_status', 'label' => 'Status Sirene', 'unit' => 'status', 'decimals' => 0, 'chart_type' => 'bar', 'states' => ['0' => 'Mati', '1' => 'Siaga', '2' => 'Berbunyi'], 'normal_min' => 0, 'normal_max' => 1, 'warning_threshold' => 2],
                ],
                'hotspots' => [
                    ['type' => 'info', 'label' => 'Menara Sirene', 'description' => 'Tinggi 12 m, tiga horn arah pemukiman.', 'yaw' => 0, 'pitch' => 14],
                ],
            ],
            [
                'code' => 'radio-ap',
                'name' => 'Radio Access Point Telemetri',
                'short_name' => 'Radio AP',
                'type' => 'network',
                'group' => 'jaringan',
                'zone' => 'Bukit Repeater',
                'latitude' => -1.9497,
                'longitude' => 119.3344,
                'elevation' => 121.600,
                'map_x' => 26.0,
                'map_y' => 20.0,
                'panorama' => 'radio-ap',
                'panorama_bearing' => 331,
                'vendor' => 'Ubiquiti',
                'model' => 'Rocket 5AC Prism',
                'telemetry_channel' => 'RADIO-AP-01',
                'installed_on' => '2024-09-05',
                'description' => 'Backhaul nirkabel 5 GHz yang mengumpulkan data seluruh stasiun lapangan menuju server monitoring kantor OP.',
                'metrics' => [
                    ['key' => 'signal_strength', 'label' => 'RSSI', 'unit' => 'dBm', 'decimals' => 0, 'is_primary' => true, 'normal_min' => -90, 'normal_max' => -45],
                    ['key' => 'link_uptime', 'label' => 'Uptime Tautan', 'unit' => '%', 'decimals' => 1, 'normal_min' => 97, 'normal_max' => 100],
                    ['key' => 'clients_connected', 'label' => 'Perangkat Terhubung', 'unit' => 'unit', 'decimals' => 0, 'chart_type' => 'bar', 'normal_min' => 1, 'normal_max' => 24],
                ],
                'hotspots' => [
                    ['type' => 'info', 'label' => 'Tower Repeater', 'description' => 'Sektoral 120° menghadap tubuh bendungan.', 'yaw' => 0, 'pitch' => 16],
                ],
            ],
        ];
    }
}
