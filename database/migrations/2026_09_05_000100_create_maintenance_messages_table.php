<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Conversation attached to a maintenance job.
     *
     * The operator in the control room and the service desk work the same
     * thread, so a request, its answers and what was finally done all live in
     * one place instead of in a chat app nobody can audit later.
     */
    public function up(): void
    {
        Schema::create('maintenance_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maintenance_task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author_name');
            $table->string('author_role', 20)->default('operator');
            $table->text('body');

            // When the *other* side opened it; drives the unread badges.
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['maintenance_task_id', 'created_at']);
        });

        Schema::table('maintenance_tasks', function (Blueprint $table) {
            // Where the job came from: the maintenance plan, or someone asking.
            $table->string('source', 20)->default('jadwal')->after('type');
            $table->foreignId('requested_by')->nullable()->after('assignee')->constrained('users')->nullOnDelete();
            $table->timestamp('last_message_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requested_by');
            $table->dropColumn(['source', 'last_message_at']);
        });

        Schema::dropIfExists('maintenance_messages');
    }
};
