<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sensor_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sensor_station_id')->constrained()->cascadeOnDelete();
            $table->string('metric_key', 60);
            $table->decimal('value', 14, 4);
            $table->string('quality', 20)->default('good');
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['sensor_station_id', 'metric_key', 'recorded_at'], 'readings_series_index');
            $table->index('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sensor_readings');
    }
};
