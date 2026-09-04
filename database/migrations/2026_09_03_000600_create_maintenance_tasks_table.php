<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sensor_station_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('type', 40)->default('preventif');
            $table->string('status', 20)->default('terjadwal');
            $table->string('priority', 20)->default('normal');
            $table->string('assignee')->nullable();
            $table->date('scheduled_for');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['dam_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_tasks');
    }
};
