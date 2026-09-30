<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Master table: aksi yang bisa dilakukan handler
        Schema::create('actions', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();      // RETURN_AND_REPLACE, REPLACE_ONLY, ...
            $table->string('name');                 // Return & Replace
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Junction: action mana yang tersedia/direkomendasikan untuk kategori tertentu
        Schema::create('category_actions', function (Blueprint $table) {
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->foreignId('action_id')->constrained('actions')->cascadeOnDelete();
            $table->boolean('is_recommended')->default(false);
            $table->timestamps();

            $table->primary(['category_id', 'action_id']);
        });

        // FK: aksi handler yang dipilih reviewer untuk tiket ini
        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('action_id')->nullable()->after('category_id')->constrained('actions')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropForeign(['action_id']);
            $table->dropColumn('action_id');
        });

        Schema::dropIfExists('category_actions');
        Schema::dropIfExists('actions');
    }
};
