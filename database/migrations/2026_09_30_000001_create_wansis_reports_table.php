<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Wansis: pindah jejak pengiriman dari 4 kolom di `tickets` ke tabel
     * tersendiri `wansis_reports` agar `tickets` clean (1 tiket bisa N report
     * utk retry). Backfill defensif: hanya baris lama yg terisi yg dipindah.
     */
    public function up(): void
    {
        Schema::create('wansis_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->string('jenis_pengajuan', 30);
            $table->string('so_number', 50);
            $table->json('payload_json');
            $table->enum('status', ['queued', 'sent', 'failed'])->default('queued');
            $table->unsignedBigInteger('wansis_report_id')->nullable();
            $table->json('response_json')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['ticket_id', 'status']);
        });

        // Backfill dari kolom lama (mayoritas NULL krn mapper lama rusak).
        if (Schema::hasColumn('tickets', 'wansis_status')) {
            $rows = DB::table('tickets')
                ->whereNotNull('wansis_status')
                ->select('id', 'wansis_report_id', 'wansis_sent_at', 'wansis_status', 'wansis_error_message')
                ->get();
            foreach ($rows as $r) {
                DB::table('wansis_reports')->insert([
                    'ticket_id' => $r->id,
                    'jenis_pengajuan' => '',
                    'so_number' => '',
                    'payload_json' => json_encode([]),
                    'status' => $r->wansis_status === 'sent' ? 'sent' : 'failed',
                    'wansis_report_id' => $r->wansis_report_id,
                    'error_message' => $r->wansis_error_message,
                    'attempt' => 1,
                    'sent_at' => $r->wansis_sent_at,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            Schema::table('tickets', function (Blueprint $table) {
                $table->dropColumn([
                    'wansis_report_id',
                    'wansis_sent_at',
                    'wansis_status',
                    'wansis_error_message',
                ]);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wansis_reports');

        if (! Schema::hasColumn('tickets', 'wansis_status')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->unsignedInteger('wansis_report_id')->nullable()->after('destination_department_id');
                $table->timestamp('wansis_sent_at')->nullable()->after('wansis_report_id');
                $table->string('wansis_status', 20)->nullable()->after('wansis_sent_at');
                $table->text('wansis_error_message')->nullable()->after('wansis_status');
            });
        }
    }
};
