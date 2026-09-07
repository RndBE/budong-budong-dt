<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Roles carry the abilities; a user carries a role slug.
     *
     * The slug is the join key (`users.role`), so the column that already
     * decided which side of the maintenance desk someone sat on keeps its
     * meaning — it now points at a row the administrator can edit.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->string('name');
            $table->string('description')->nullable();

            // Which side of the maintenance desk: operator or cs.
            $table->string('desk_side', 20)->default('operator');

            // Ability codes from config/access.php.
            $table->json('permissions')->nullable();

            // System roles cannot be deleted or renamed away from their slug.
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            // A disabled account keeps its history but cannot sign in.
            $table->boolean('is_active')->default(true)->after('unit');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });

        Schema::dropIfExists('roles');
    }
};
