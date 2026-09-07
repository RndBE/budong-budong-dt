<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Roles first: a user row points at one by slug.
        $this->call(RoleSeeder::class);

        User::query()->updateOrCreate(
            ['email' => 'admin@bwssulawesi5.go.id'],
            [
                'name' => 'User Admin',
                'role' => 'admin',
                'unit' => 'BWS Sulawesi V — Unit OP Bendungan',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );

        User::query()->updateOrCreate(
            ['email' => 'operator@bwssulawesi5.go.id'],
            [
                'name' => 'Operator Bendungan',
                'role' => 'operator',
                'unit' => 'Petugas OP Bendungan Budong Budong',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );

        $this->call([
            DamSeeder::class,
            StationSeeder::class,
            ReadingSeeder::class,
            OperationSeeder::class,
        ]);
    }
}
