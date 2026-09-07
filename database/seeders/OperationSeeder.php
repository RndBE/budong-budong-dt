<?php

namespace Database\Seeders;

use App\Models\Alert;
use App\Models\Dam;
use App\Models\MaintenanceMessage;
use App\Models\MaintenanceTask;
use App\Models\SensorStation;
use App\Models\Setting;
use App\Models\User;
use App\Services\AlertEvaluator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Operational context around the readings: the two standing warnings shown in
 * the reference dashboard, a maintenance backlog, and display preferences.
 */
class OperationSeeder extends Seeder
{
    public function __construct(
        private readonly AlertEvaluator $evaluator,
    ) {}

    public function run(): void
    {
        $dam = Dam::query()->where('code', 'budong-budong')->firstOrFail();
        $stations = SensorStation::query()->where('dam_id', $dam->id)->get()->keyBy('code');
        $now = CarbonImmutable::now($dam->timezone);

        Alert::query()->where('dam_id', $dam->id)->delete();

        Alert::query()->create([
            'dam_id' => $dam->id,
            'sensor_station_id' => $stations['awr-01']->id,
            'level' => 'waspada',
            'category' => 'hidrologi',
            'title' => 'Waspada Curah Hujan',
            'message' => 'Curah hujan > 50 mm dalam 24 jam pada pos AWR-01. Tingkatkan pemantauan inflow dan siapkan operasi pintu.',
            'metric_key' => 'rainfall_24h',
            'value' => 56.4,
            'threshold' => 50,
            'triggered_at' => $now->subHours(2)->setTime(8, 45),
        ]);

        Alert::query()->create([
            'dam_id' => $dam->id,
            'sensor_station_id' => $stations['awlr-hulu']->id,
            'level' => 'waspada',
            'category' => 'hidrologi',
            'title' => 'Perubahan Muka Air Cepat',
            'message' => 'Perubahan muka air > 0,5 m dalam 1 jam. Verifikasi bacaan radar dengan papan duga manual.',
            'metric_key' => 'water_level',
            'value' => 0.54,
            'threshold' => 0.5,
            'triggered_at' => $now->subHours(3)->setTime(7, 30),
        ]);

        Alert::query()->create([
            'dam_id' => $dam->id,
            'sensor_station_id' => $stations['v-notch']->id,
            'level' => 'waspada',
            'category' => 'geoteknik',
            'title' => 'Debit Rembesan Naik',
            'message' => 'Debit rembesan V-notch naik 18% dibanding rata-rata 7 hari, kekeruhan masih normal.',
            'metric_key' => 'seepage_flow',
            'value' => 15.2,
            'threshold' => 14.5,
            'triggered_at' => $now->subDay()->setTime(21, 10),
            'resolved_at' => $now->subHours(9),
        ]);

        MaintenanceTask::query()->where('dam_id', $dam->id)->delete();

        $tasks = [
            ['station' => 'avwr-01', 'title' => 'Kalibrasi ulang piezometer VW zona inti', 'type' => 'kalibrasi', 'status' => 'terjadwal', 'priority' => 'tinggi', 'assignee' => 'Tim Instrumentasi', 'days' => 4],
            ['station' => 'awr-01', 'title' => 'Bersihkan penakar hujan dan panel surya', 'type' => 'preventif', 'status' => 'terjadwal', 'priority' => 'normal', 'assignee' => 'Petugas OP Pagi', 'days' => 2],
            ['station' => 'adr-01', 'title' => 'Verifikasi prisma hilang pada blok tengah', 'type' => 'korektif', 'status' => 'berjalan', 'priority' => 'tinggi', 'assignee' => 'Surveyor Geodesi', 'days' => 0],
            ['station' => 'cctv-02', 'title' => 'Ganti housing kamera spillway yang berkabut', 'type' => 'korektif', 'status' => 'tertunda', 'priority' => 'normal', 'assignee' => 'Teknisi Elektronik', 'days' => -3],
            ['station' => 'v-notch', 'title' => 'Pengukuran manual debit rembesan pembanding', 'type' => 'inspeksi', 'status' => 'selesai', 'priority' => 'normal', 'assignee' => 'Tim Geoteknik', 'days' => -1],
            ['station' => 'radio-ap', 'title' => 'Audit link budget backhaul 5 GHz', 'type' => 'preventif', 'status' => 'terjadwal', 'priority' => 'rendah', 'assignee' => 'Tim Jaringan', 'days' => 9],
            ['station' => 'ews-01', 'title' => 'Uji fungsi sirene mingguan', 'type' => 'preventif', 'status' => 'selesai', 'priority' => 'normal', 'assignee' => 'Petugas OP Siang', 'days' => -2],
            ['station' => 'gnss-tilt', 'title' => 'Perbaikan grounding pilar GNSS', 'type' => 'korektif', 'status' => 'terjadwal', 'priority' => 'tinggi', 'assignee' => 'Tim Instrumentasi', 'days' => 6],
        ];

        $created = [];

        foreach ($tasks as $task) {
            $scheduled = $now->addDays($task['days']);

            $created[$task['station']] = MaintenanceTask::query()->create([
                'dam_id' => $dam->id,
                'sensor_station_id' => $stations[$task['station']]->id,
                'title' => $task['title'],
                'type' => $task['type'],
                'status' => $task['status'],
                'priority' => $task['priority'],
                'assignee' => $task['assignee'],
                'scheduled_for' => $scheduled->toDateString(),
                'started_at' => in_array($task['status'], ['berjalan', 'selesai'], true) ? $scheduled->setTime(8, 0) : null,
                'completed_at' => $task['status'] === 'selesai' ? $scheduled->setTime(11, 30) : null,
                'notes' => $task['status'] === 'tertunda' ? 'Menunggu suku cadang housing dari gudang pusat.' : null,
            ]);
        }

        $this->seedMaintenanceThreads($created, $now);

        Setting::put('map_skin', 'auto', 'tampilan');
        Setting::put('panorama_auto_rotate', true, 'tampilan');
        Setting::put('marker_labels', true, 'tampilan');

        // Raise any alert the seeded readings actually justify.
        $this->evaluator->evaluateAll($dam->id);
    }

    /**
     * Two jobs already carry a conversation, so a fresh install shows the desk
     * working rather than an empty box.
     *
     * @param  array<string, MaintenanceTask>  $tasks
     */
    private function seedMaintenanceThreads(array $tasks, CarbonImmutable $now): void
    {
        $operator = User::query()->where('role', 'operator')->first();
        $desk = User::query()->where('role', '!=', 'operator')->first();

        if (! $operator || ! $desk) {
            return;
        }

        $threads = [
            'cctv-02' => [
                ['operator', 'Housing kamera spillway berkabut lagi sejak hujan semalam, rekamannya buram. Bisa dijadwalkan penggantian?', 30],
                ['cs', 'Diterima. Housing pengganti ada di gudang pusat, perkiraan tiba Kamis. Tiket saya tahan di status tertunda dulu.', 26],
                ['operator', 'Baik. Kalau bisa sekalian periksa segel kabel di tiang yang sama.', 20],
                ['cs', 'Dicatat, teknisi akan membawa segel cadangan.', 6],
            ],
            'adr-01' => [
                ['operator', 'Prisma blok tengah tidak terbaca sejak pagi, hasil pengukuran melompat 2 mm.', 10],
                ['cs', 'Surveyor sedang menuju lokasi. Kemungkinan prisma bergeser terkena ranting; laporannya menyusul hari ini.', 4],
            ],
        ];

        foreach ($threads as $code => $lines) {
            $task = $tasks[$code] ?? null;

            if (! $task) {
                continue;
            }

            $last = null;

            foreach ($lines as [$role, $body, $hoursAgo]) {
                $author = $role === 'operator' ? $operator : $desk;
                $at = $now->subHours($hoursAgo);
                $last = $at;

                MaintenanceMessage::query()->create([
                    'maintenance_task_id' => $task->id,
                    'user_id' => $author->id,
                    'author_name' => $author->name,
                    'author_role' => $role,
                    'body' => $body,
                    // The desk's latest word is left unread on purpose.
                    'read_at' => $role === 'cs' && $hoursAgo < 12 ? null : $at->addMinutes(20),
                    'created_at' => $at,
                    'updated_at' => $at,
                ]);
            }

            $task->forceFill(['last_message_at' => $last])->save();
        }
    }
}
