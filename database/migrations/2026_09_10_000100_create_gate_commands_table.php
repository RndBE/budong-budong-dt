<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an operator asked a spillway gate to do.
 *
 * A command is not a reading. A reading says where the gate is; this says
 * where somebody told it to go, who told it, and when — which is the part a
 * flood report has to be able to quote. Keeping them apart is also what lets
 * the simulator drive the gate towards the order instead of overwriting it
 * with the next generated row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gate_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sensor_station_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('gate');
            $table->decimal('opening', 5, 2);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('issued_at');
            $table->timestamps();

            // The standing order for one gate is the newest one before a
            // moment, which is the only way this is ever read.
            $table->index(['sensor_station_id', 'gate', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gate_commands');
    }
};
