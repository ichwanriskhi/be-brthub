<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Visibilitas & soft-delete untuk obrolan tiket (ticket_interactions).
     *
     * - is_internal: pesan hanya untuk konsumsi internal (approver, unit,
     *   admin, reviewer, handler). Reporter tidak melihatnya.
     * - deleted_at: hapus (soft delete) pesan, audit trail tetap utuh.
     */
    public function up(): void
    {
        Schema::table('ticket_interactions', function (Blueprint $table) {
            $table->boolean('is_internal')->default(false)->after('interaction_type');
            $table->timestamp('deleted_at')->nullable()->after('updated_at');

            // Daftar pesan sebuah tiket (urut waktu) + cek pending tidy-up
            $table->index(['ticket_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('ticket_interactions', function (Blueprint $table) {
            $table->dropIndex(['ticket_id', 'deleted_at']);
            $table->dropColumn(['is_internal', 'deleted_at']);
        });
    }
};
