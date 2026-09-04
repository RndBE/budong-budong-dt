<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dam_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('period', 20)->default('harian');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('format', 10)->default('pdf');
            $table->json('sections')->nullable();
            $table->json('summary')->nullable();
            $table->string('file_path')->nullable();
            $table->string('generated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
