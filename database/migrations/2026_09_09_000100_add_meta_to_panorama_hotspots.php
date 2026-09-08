<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Some hotspots are an area, not a point.
     *
     * A monitoring plot ("petak") on the dam face carries the stakes inside it,
     * so it needs an angular span and a stake count as well as a centre. Those
     * belong to the one type that has them rather than to four columns every
     * other hotspot would leave null.
     */
    public function up(): void
    {
        Schema::table('panorama_hotspots', function (Blueprint $table) {
            $table->json('meta')->nullable()->after('pitch');
        });
    }

    public function down(): void
    {
        Schema::table('panorama_hotspots', function (Blueprint $table) {
            $table->dropColumn('meta');
        });
    }
};
