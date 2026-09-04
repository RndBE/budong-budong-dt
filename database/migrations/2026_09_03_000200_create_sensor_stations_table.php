<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sensor_stations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dam_id')->constrained()->cascadeOnDelete();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('short_name')->nullable();
            $table->string('type', 40)->index();
            $table->string('group', 40)->default('instrumentasi');
            $table->string('zone', 60)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('elevation', 8, 3)->nullable();
            $table->decimal('map_x', 6, 3);
            $table->decimal('map_y', 6, 3);
            $table->string('panorama')->nullable();
            $table->decimal('panorama_yaw', 8, 3)->default(0);
            $table->decimal('panorama_pitch', 8, 3)->default(0);
            $table->decimal('panorama_north_offset', 8, 3)->default(0);
            $table->string('status', 20)->default('normal');
            $table->boolean('is_online')->default(true);
            $table->string('vendor')->nullable();
            $table->string('model')->nullable();
            $table->string('telemetry_channel')->nullable();
            $table->date('installed_on')->nullable();
            $table->date('calibrated_on')->nullable();
            $table->text('description')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['dam_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sensor_stations');
    }
};
