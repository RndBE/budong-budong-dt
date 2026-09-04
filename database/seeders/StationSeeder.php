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
    public function run(): void
    {
        $dam = Dam::query()->where('code', 'budong-budong')->firstOrFail();

        foreach ($this->catalogue() as $definition) {
            $metrics = $definition['metrics'] ?? [];
            $hotspots = $definition['hotspots'] ?? [];
            unset($definition['metrics'], $definition['hotspots']);

            /** @var SensorStation $station */
            $station = SensorStation::query()->updateOrCreate(
                ['code' => $definition['code']],
                $definition + ['dam_id' => $dam->id],
            );

            foreach (array_values($metrics) as $index => $metric) {
                SensorMetric::query()->updateOrCreate(
                    ['sensor_station_id' => $station->id, 'key' => $metric['key']],
                    $metric + ['sort_order' => $index],
                );
            }

            $station->hotspots()->delete();
            foreach (array_values($hotspots) as $index => $hotspot) {
                PanoramaHotspot::query()->create($hotspot + [
                    'sensor_station_id' => $station->id,
                    'sort_order' => $index,
                ]);
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
            $order = $station?->hotspots()->count() ?? 0;

            foreach ($definitions as $definition) {
                $target = SensorStation::query()->where('code', $definition['target'])->first();

                if (! $station || ! $target) {
                    continue;
                }

                PanoramaHotspot::query()->create([
                    'sensor_station_id' => $station->id,
                    'target_station_id' => $target->id,
                    'type' => 'link',
                    'label' => $definition['label'],
                    'yaw' => $definition['yaw'],
                    'pitch' => $definition['pitch'],
                    'sort_order' => $order++,
                ]);
            }
        }
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
                'vendor' => 'Beacon Engineering',
                'model' => 'BE-WLR-100-U150',
                'telemetry_channel' => 'AWLR-HILIR',
                'installed_on' => '2024-09-14',
                'description' => 'Pemantauan muka air dan debit sungai di hilir bendungan untuk verifikasi pelepasan air.',
                'metrics' => [
                    ['key' => 'water_level', 'label' => 'Muka Air Sungai', 'unit' => 'mdpl', 'decimals' => 3, 'is_primary' => true, 'chart_type' => 'area', 'normal_min' => 26.0, 'normal_max' => 29.5, 'warning_threshold' => 30.0, 'alert_threshold' => 31.0, 'critical_threshold' => 32.0],
                    ['key' => 'discharge', 'label' => 'Debit Sungai', 'unit' => 'm³/s', 'decimals' => 2, 'chart_type' => 'area', 'normal_min' => 0, 'normal_max' => 70, 'warning_threshold' => 90, 'alert_threshold' => 130],
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
                'vendor' => 'Beacon Engineering',
                'model' => 'BE-AWGC-3G',
                'telemetry_channel' => 'AWGC-01',
                'installed_on' => '2024-10-02',
                'description' => 'Monitoring bukaan pintu dan debit limpasan pelimpah, termasuk tinggi limpasan di atas ambang.',
                'metrics' => [
                    ['key' => 'discharge', 'label' => 'Outflow (Qout)', 'unit' => 'm³/s', 'decimals' => 2, 'is_primary' => true, 'chart_type' => 'area', 'normal_min' => 0, 'normal_max' => 70, 'warning_threshold' => 90, 'alert_threshold' => 130, 'critical_threshold' => 180],
                    ['key' => 'gate_opening', 'label' => 'Bukaan Pintu', 'unit' => '%', 'decimals' => 1, 'chart_type' => 'bar', 'normal_min' => 0, 'normal_max' => 100],
                    ['key' => 'head_over_crest', 'label' => 'Tinggi Limpasan', 'unit' => 'm', 'decimals' => 3, 'normal_min' => 0, 'normal_max' => 1.5, 'warning_threshold' => 2.0, 'alert_threshold' => 2.6],
                ],
                'hotspots' => [
                    ['type' => 'metric', 'label' => 'Rumah Hoist Pintu', 'metric_key' => 'gate_opening', 'description' => 'Tiga pintu radial dengan aktuator hidrolik.', 'yaw' => -6, 'pitch' => -2],
                    ['type' => 'info', 'label' => 'Saluran Peluncur', 'description' => 'Chute beton dengan stilling basin di ujung hilir.', 'yaw' => 42, 'pitch' => -22],
                ],
            ],
            [
                'code' => 'awlr-awqr-sedimen',
                'name' => 'AWLR, AWQR & Sedimen Sungai',
                'short_name' => 'AWQR Sungai',
                'type' => 'water_quality',
                'group' => 'hidrologi',
                'zone' => 'Hulu Sungai',
                'latitude' => -1.9418,
                'longitude' => 119.3521,
                'elevation' => 27.360,
                'map_x' => 79.3,
                'map_y' => 22.7,
                'panorama' => 'awlr-awqr-sedimen',
                'vendor' => 'Beacon Engineering',
                'model' => 'BE-AWQR-5P',
                'telemetry_channel' => 'AWQR-01',
                'installed_on' => '2024-11-08',
                'description' => 'Stasiun gabungan muka air, kualitas air, dan muatan sedimen di sungai masuk waduk.',
                'metrics' => [
                    ['key' => 'turbidity', 'label' => 'Kekeruhan', 'unit' => 'NTU', 'decimals' => 1, 'is_primary' => true, 'chart_type' => 'area', 'normal_min' => 0, 'normal_max' => 25, 'warning_threshold' => 40, 'alert_threshold' => 80, 'critical_threshold' => 150],
                    ['key' => 'water_level', 'label' => 'Muka Air Sungai', 'unit' => 'mdpl', 'decimals' => 3, 'normal_min' => 24, 'normal_max' => 30.5],
                    ['key' => 'ph', 'label' => 'pH', 'unit' => '', 'decimals' => 2, 'normal_min' => 6.5, 'normal_max' => 8.5],
                    ['key' => 'dissolved_oxygen', 'label' => 'Oksigen Terlarut', 'unit' => 'mg/L', 'decimals' => 2, 'normal_min' => 5, 'normal_max' => 9],
                    ['key' => 'sediment_load', 'label' => 'Muatan Sedimen', 'unit' => 'mg/L', 'decimals' => 1, 'chart_type' => 'bar', 'normal_min' => 0, 'normal_max' => 150, 'warning_threshold' => 220, 'alert_threshold' => 350],
                ],
                'hotspots' => [
                    ['type' => 'metric', 'label' => 'Sonde Multiparameter', 'metric_key' => 'turbidity', 'yaw' => -12, 'pitch' => -16],
                    ['type' => 'metric', 'label' => 'Sampler Sedimen', 'metric_key' => 'sediment_load', 'yaw' => 96, 'pitch' => -14],
                ],
            ],
            [
                'code' => 'awr-01',
                'name' => 'AWR — Stasiun Cuaca Otomatis',
                'short_name' => 'AWR / Pos Hujan',
                'type' => 'weather',
                'group' => 'hidrologi',
                'zone' => 'Kantor OP',
                'latitude' => -1.9558,
                'longitude' => 119.3356,
                'elevation' => 92.800,
                'map_x' => 27.9,
                'map_y' => 36.5,
                'panorama' => 'awr-01',
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
                    ['key' => 'wind_speed', 'label' => 'Kecepatan Angin', 'unit' => 'km/jam', 'decimals' => 1, 'normal_min' => 0, 'normal_max' => 45, 'warning_threshold' => 55],
                    ['key' => 'solar_radiation', 'label' => 'Radiasi Matahari', 'unit' => 'W/m²', 'decimals' => 0, 'normal_min' => 0, 'normal_max' => 1100],
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
                ],
                'hotspots' => [
                    ['type' => 'metric', 'label' => 'Total Station', 'metric_key' => 'displacement_h', 'yaw' => -4, 'pitch' => -8],
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
                'vendor' => 'Geokon',
                'model' => '4500S + BE-AVWR Logger',
                'telemetry_channel' => 'AVWR-01',
                'installed_on' => '2024-12-05',
                'calibrated_on' => '2026-05-22',
                'description' => 'Rangkaian piezometer vibrating wire pada inti dan filter; indikator utama tekanan air pori tubuh bendungan.',
                'metrics' => [
                    ['key' => 'pore_pressure', 'label' => 'Tekanan Air Pori', 'unit' => 'kPa', 'decimals' => 1, 'is_primary' => true, 'chart_type' => 'area', 'normal_min' => 150, 'normal_max' => 280, 'warning_threshold' => 262, 'alert_threshold' => 330, 'critical_threshold' => 380],
                    ['key' => 'piezo_level', 'label' => 'Tinggi Piezometrik', 'unit' => 'mdpl', 'decimals' => 2, 'normal_min' => 84, 'normal_max' => 91],
                    ['key' => 'water_column', 'label' => 'Kolom Air', 'unit' => 'm', 'decimals' => 2, 'normal_min' => 8, 'normal_max' => 16],
                    ['key' => 'battery_voltage', 'label' => 'Tegangan Baterai', 'unit' => 'V', 'decimals' => 2, 'normal_min' => 11.8, 'normal_max' => 14.5],
                ],
                'hotspots' => [
                    ['type' => 'metric', 'label' => 'Terminal Box Piezometer', 'metric_key' => 'pore_pressure', 'description' => '8 kanal vibrating wire pada dua elevasi.', 'yaw' => 0, 'pitch' => -18],
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
                'type' => 'deformation',
                'group' => 'geoteknik',
                'zone' => 'Lereng Hilir Kanan',
                'latitude' => -1.9556,
                'longitude' => 119.3471,
                'elevation' => 97.700,
                'map_x' => 82.6,
                'map_y' => 57.4,
                'panorama' => 'gnss-tilt',
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
                'name' => 'CCTV-01 — Puncak Bendungan',
                'short_name' => 'CCTV Puncak',
                'type' => 'cctv',
                'group' => 'pengawasan',
                'zone' => 'Puncak',
                'latitude' => -1.9532,
                'longitude' => 119.3408,
                'elevation' => 101.200,
                'map_x' => 51.2,
                'map_y' => 15.4,
                'panorama' => 'cctv-01',
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
                'code' => 'cctv-02',
                'name' => 'CCTV-02 — Spillway dan Stilling Basin',
                'short_name' => 'CCTV Spillway',
                'type' => 'cctv',
                'group' => 'pengawasan',
                'zone' => 'Spillway',
                'latitude' => -1.9566,
                'longitude' => 119.3452,
                'elevation' => 78.500,
                'map_x' => 78.0,
                'map_y' => 46.0,
                'panorama' => 'cctv-02',
                'vendor' => 'Hikvision',
                'model' => 'DS-2DE7A425IW-AEB',
                'telemetry_channel' => 'CCTV-02',
                'installed_on' => '2025-04-22',
                'description' => 'Kamera pengawas saluran peluncur dan kolam olak untuk verifikasi visual saat pelimpasan.',
                'metrics' => [
                    ['key' => 'stream_bitrate', 'label' => 'Bitrate Stream', 'unit' => 'Mbps', 'decimals' => 2, 'is_primary' => true, 'normal_min' => 1, 'normal_max' => 8],
                    ['key' => 'frame_rate', 'label' => 'Frame Rate', 'unit' => 'fps', 'decimals' => 0, 'normal_min' => 15, 'normal_max' => 30],
                    ['key' => 'link_uptime', 'label' => 'Uptime Perangkat', 'unit' => '%', 'decimals' => 1, 'normal_min' => 95, 'normal_max' => 100],
                ],
                'hotspots' => [
                    ['type' => 'info', 'label' => 'Kolam Olak', 'description' => 'Stilling basin tipe USBR II.', 'yaw' => 18, 'pitch' => -24],
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
                'vendor' => 'Beacon Engineering',
                'model' => 'BE-EWS-120',
                'telemetry_channel' => 'EWS-01',
                'installed_on' => '2025-05-30',
                'description' => 'Sirene peringatan dini 120 dB dengan pengeras suara arah desa hilir; diuji otomatis setiap Jumat pukul 10.00 WITA.',
                'metrics' => [
                    ['key' => 'siren_battery', 'label' => 'Baterai Sirene', 'unit' => '%', 'decimals' => 0, 'is_primary' => true, 'normal_min' => 60, 'normal_max' => 100],
                    ['key' => 'siren_range', 'label' => 'Jangkauan Suara', 'unit' => 'km', 'decimals' => 2, 'normal_min' => 1.2, 'normal_max' => 3.0],
                    ['key' => 'signal_strength', 'label' => 'Kuat Sinyal', 'unit' => 'dBm', 'decimals' => 0, 'normal_min' => -95, 'normal_max' => -45],
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
