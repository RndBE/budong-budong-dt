<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Some parameters are states, not quantities.
     *
     * "Kondisi pintu" or "status sirene" is a word, but a reading is a number
     * — so the number keeps its place in the series and the metric carries the
     * words it stands for. That keeps one storage shape for everything: the
     * state still charts as a step line and still has thresholds.
     */
    public function up(): void
    {
        Schema::table('sensor_metrics', function (Blueprint $table) {
            $table->json('states')->nullable()->after('unit');
        });
    }

    public function down(): void
    {
        Schema::table('sensor_metrics', function (Blueprint $table) {
            $table->dropColumn('states');
        });
    }
};
