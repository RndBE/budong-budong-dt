<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which way a station's panorama is meant to open, as a compass bearing.
 *
 * `panorama_yaw` is measured from the picture, so it moves the moment anyone
 * corrects `panorama_north_offset` for that render. A bearing is measured from
 * north and survives the correction — the same reason the base panorama opens
 * on `dam.stage.default_bearing` rather than on a yaw. Null means the station
 * keeps opening on its raw yaw.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sensor_stations', function (Blueprint $table) {
            $table->decimal('panorama_bearing', 8, 3)->nullable()->after('panorama_north_offset');
        });
    }

    public function down(): void
    {
        Schema::table('sensor_stations', function (Blueprint $table) {
            $table->dropColumn('panorama_bearing');
        });
    }
};
