<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dams', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('authority')->nullable();
            $table->string('river')->nullable();
            $table->string('regency')->nullable();
            $table->string('province')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('timezone')->default('Asia/Makassar');
            $table->smallInteger('utc_offset_minutes')->default(480);
            $table->decimal('crest_elevation', 8, 3)->nullable();
            $table->decimal('normal_water_level', 8, 3)->nullable();
            $table->decimal('flood_water_level', 8, 3)->nullable();
            $table->decimal('minimum_water_level', 8, 3)->nullable();
            $table->decimal('gross_storage_mcm', 10, 3)->nullable();
            $table->string('map_asset_prefix')->default('map');
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dams');
    }
};
