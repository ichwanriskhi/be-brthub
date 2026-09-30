<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── tickets: trace penolakan & unit tujuan (sebelumnya hanya ada di review_logs) ──
        if (! Schema::hasColumn('tickets', 'rejection_reason')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->text('rejection_reason')->nullable()->after('description');
            });
        }

        if (! Schema::hasColumn('tickets', 'destination_department_id')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->foreignId('destination_department_id')
                    ->nullable()
                    ->constrained('departments')
                    ->nullOnDelete()
                    ->after('approval_type');
            });
        }

        // ── ticket_statuses: tidak ada penambahan — PENDING_REVIEW (sort=4)
        // sudah tepat sebagai "menunggu persetujuan penutupan".
        // Alur: OPEN → PENDING_APPROVAL → IN_PROGRESS → PENDING_REVIEW → CLOSED.
    }

    public function down(): void
    {
        if (Schema::hasColumn('tickets', 'destination_department_id')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->dropConstrainedForeignId('destination_department_id');
            });
        }

        if (Schema::hasColumn('tickets', 'rejection_reason')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->dropColumn('rejection_reason');
            });
        }
    }
};
