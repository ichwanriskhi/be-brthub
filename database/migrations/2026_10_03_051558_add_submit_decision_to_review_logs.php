<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `review_logs.decision` tidak punya nilai `SUBMIT`.
 *
 * `HandlerController::submitResolution()` menulis `'decision' => 'SUBMIT'` untuk
 * mencatat pengajuan resolusi handler, dan tulisan itu berada di dalam
 * `DB::transaction`. Karena MySQL dijalankan dalam strict mode
 * (`config/database.php` → mysql `'strict' => true`), nilai di luar daftar enum
 * ditolak:
 *
 *   SQLSTATE[01000]: Warning: 1265 Data truncated for column 'decision' at row 1
 *
 * Akibatnya seluruh pengajuan resolusi **rollback** — handler tidak bisa
 * mengajukan resolusi sama sekali, bukan cuma log-nya yang gagal.
 *
 * Nilai `SUBMIT` ditambahkan, bukan memakai ulang `CLOSE`, karena keduanya
 * berbeda reimburse: `CLOSE` menandai tiket benar-benar ditutup oleh approver,
 * sedangkan `SUBMIT` menandai handler mengajukan resolusi yang masih menunggu
 * persetujuan. Kalau keduanya disamakan, jejak "resolusi diajukan" dan
 * "tiket ditutup" tidak bisa dibedakan saat membuat laporan audit.
 *
 * Catatan: enum tidak diubah in-place di klausa `up()` memakai nilai baru
 * langsung. Kolom enum MySQL harus ditulis ulang agar aman terhadap data yang
 * sudah ada; daftar lama tetap terbaca karena tidak ada baris `review_logs`
 * yang memakai nilai di luar daftar lama.
 */
return new class extends Migration
{
    /** Urutan nilai enum final di `review_logs.decision`. */
    private const DECISIONS = ['ROUTE', 'REQUEST_REWORK', 'APPROVE', 'REJECT', 'CLOSE', 'SUBMIT'];

    /** Nilai enum sebelum migrasi ini. */
    private const PREVIOUS_DECISIONS = ['ROUTE', 'REQUEST_REWORK', 'APPROVE', 'REJECT', 'CLOSE'];

    public function up(): void
    {
        Schema::table('review_logs', function (Blueprint $table): void {
            $table->enum('decision', self::DECISIONS)->change();
        });
    }

    public function down(): void
    {
        // Baris `SUBMIT` tidak punya padanan di daftar lama, jadi dibuang lebih
        // dulu. Menyisakan baris tak_valid akan membuat ALTER gagal karena
        // strict mode.
        DB::table('review_logs')
            ->where('decision', 'SUBMIT')
            ->delete();

        Schema::table('review_logs', function (Blueprint $table): void {
            $table->enum('decision', self::PREVIOUS_DECISIONS)->change();
        });
    }
};
