<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * The four roles an install starts with.
     *
     * Only the administrator is re-synced on every run — it is the account
     * that hands out access, so a newly added ability must reach it. The rest
     * are created once and then belong to whoever administers the site.
     */
    public function run(): void
    {
        Role::query()->updateOrCreate(
            ['slug' => 'admin'],
            [
                'name' => 'Administrator',
                'description' => 'Mengelola pengguna, peran, dan seluruh fitur sistem.',
                'desk_side' => 'cs',
                'permissions' => Role::abilities(),
                'is_system' => true,
            ],
        );

        $defaults = [
            [
                'slug' => 'operator',
                'name' => 'Operator Ruang Kendali',
                'description' => 'Memantau bendungan dan mengajukan perawatan alat.',
                'desk_side' => 'operator',
                'is_system' => true,
                'permissions' => [
                    'maintenance.view',
                    'maintenance.request',
                    'maintenance.reply',
                    'stations.move',
                    'alerts.handle',
                    'reports.create',
                ],
            ],
            [
                'slug' => 'teknisi',
                'name' => 'Layanan Teknis',
                'description' => 'Menjawab permintaan perawatan dan mengerjakan tugasnya.',
                'desk_side' => 'cs',
                'is_system' => false,
                'permissions' => [
                    'maintenance.view',
                    'maintenance.reply',
                    'maintenance.status',
                ],
            ],
            [
                'slug' => 'pengawas',
                'name' => 'Pengawas',
                'description' => 'Membaca kondisi bendungan dan menarik laporan.',
                'desk_side' => 'operator',
                'is_system' => false,
                'permissions' => [
                    'maintenance.view',
                    'reports.create',
                ],
            ],
        ];

        foreach ($defaults as $role) {
            Role::query()->firstOrCreate(['slug' => $role['slug']], $role);
        }
    }
}
