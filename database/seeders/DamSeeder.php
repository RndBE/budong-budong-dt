<?php

namespace Database\Seeders;

use App\Models\Dam;
use Illuminate\Database\Seeder;

class DamSeeder extends Seeder
{
    public function run(): void
    {
        Dam::query()->updateOrCreate(
            ['code' => 'budong-budong'],
            [
                'name' => 'Bendungan Budong Budong',
                'authority' => 'BWS Sulawesi V',
                'river' => 'Sungai Budong Budong',
                'regency' => 'Mamuju Tengah',
                'province' => 'Sulawesi Barat',
                'latitude' => -1.9536,
                'longitude' => 119.3411,
                'timezone' => 'Asia/Makassar',
                'utc_offset_minutes' => 480,
                'crest_elevation' => 100.500,
                'normal_water_level' => 94.000,
                'flood_water_level' => 97.400,
                'minimum_water_level' => 82.000,
                'gross_storage_mcm' => 66.500,
                'map_asset_prefix' => 'map',
                'meta' => [
                    'tipe' => 'Bendungan urugan batu dengan inti tanah',
                    'tinggi' => '58 m dari dasar sungai',
                    'panjang_puncak' => '415 m',
                    'luas_genangan' => '281 ha',
                    'manfaat' => ['Irigasi 3.577 ha', 'Air baku 50 l/detik', 'Reduksi banjir', 'PLTM 1,2 MW'],
                ],
            ],
        );
    }
}
