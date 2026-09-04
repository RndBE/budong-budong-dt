<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sensor_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sensor_station_id')->constrained()->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('label');
            $table->string('unit', 20)->nullable();
            $table->unsignedTinyInteger('decimals')->default(2);
            $table->boolean('is_primary')->default(false);
            $table->string('chart_type', 20)->default('line');
            $table->decimal('normal_min', 12, 4)->nullable();
            $table->decimal('normal_max', 12, 4)->nullable();
            $table->decimal('warning_threshold', 12, 4)->nullable();
            $table->decimal('alert_threshold', 12, 4)->nullable();
            $table->decimal('critical_threshold', 12, 4)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['sensor_station_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sensor_metrics');
    }
};
