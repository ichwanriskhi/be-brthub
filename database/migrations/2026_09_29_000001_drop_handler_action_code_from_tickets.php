<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kolom ini sudah tidak pernah ditulis sejak migrasi
     * 2026_09_23_034054 memperkenalkan `tickets.action_id` (FK -> actions)
     * sebagai penyimpanan aksi handler yang dipilih reviewer. Seluruh baris
     * juga terverifikasi NULL di database (0 dari 7 tiket terisi), dan tidak
     * ada kode di monorepo ini yang membacanya.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('handler_action_code');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('handler_action_code', 50)->nullable()->after('approval_type');
        });
    }
};