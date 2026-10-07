<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * position_role_mappings tidak pernah dipakai: tanpa seeder, tanpa
     * admin UI, tanpa penulis. Assign role sepenuhnya manual via user_roles,
     * dan kewenangan approver diturunkan dari hierarchy_level — bukan tabel ini.
     */
    public function up(): void
    {
        Schema::dropIfExists('position_role_mappings');
    }

    public function down(): void
    {
        Schema::create('position_role_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('position_id')->constrained('positions')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }
};
