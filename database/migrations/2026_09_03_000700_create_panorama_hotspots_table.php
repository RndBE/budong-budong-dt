<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panorama_hotspots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sensor_station_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20)->default('metric');
            $table->string('label');
            $table->text('description')->nullable();
            $table->string('metric_key', 60)->nullable();
            $table->foreignId('target_station_id')->nullable()->constrained('sensor_stations')->nullOnDelete();
            $table->decimal('yaw', 8, 3);
            $table->decimal('pitch', 8, 3);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panorama_hotspots');
    }
};
