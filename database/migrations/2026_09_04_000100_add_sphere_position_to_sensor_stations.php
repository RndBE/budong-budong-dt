<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a station sits inside the base panorama of the dam, which the
     * digital twin stage now shows instead of the orthographic render.
     *
     * Null means "not placed yet": the stage then derives a bearing from the
     * plan-view map percentages, and dragging the pin stores the real angles.
     */
    public function up(): void
    {
        Schema::table('sensor_stations', function (Blueprint $table) {
            $table->decimal('sphere_yaw', 8, 3)->nullable()->after('panorama_north_offset');
            $table->decimal('sphere_pitch', 8, 3)->nullable()->after('sphere_yaw');
        });
    }

    public function down(): void
    {
        Schema::table('sensor_stations', function (Blueprint $table) {
            $table->dropColumn(['sphere_yaw', 'sphere_pitch']);
        });
    }
};
