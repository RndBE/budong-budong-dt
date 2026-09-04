<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sensor_station_id')->nullable()->constrained()->nullOnDelete();
            $table->string('level', 20);
            $table->string('category', 40)->default('instrumentasi');
            $table->string('title');
            $table->text('message')->nullable();
            $table->string('metric_key', 60)->nullable();
            $table->decimal('value', 14, 4)->nullable();
            $table->decimal('threshold', 14, 4)->nullable();
            $table->timestamp('triggered_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('acknowledged_by')->nullable();
            $table->timestamps();

            $table->index(['dam_id', 'level', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
