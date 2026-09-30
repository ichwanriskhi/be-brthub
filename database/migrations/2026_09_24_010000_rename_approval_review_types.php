<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * review_type enum sebelumnya: INITIAL | RESOLUTION | FINAL_CLOSURE.
 *
 * `RESOLUTION` & `FINAL_CLOSURE` dipakai ApprovalController untuk mencatat
 * approval awal / penutupan, padahal `RESOLUTION` secara konsep adalah
 * resolusi yang dikerjakan handler (lihat kolom resolution_id yang terhubung
 * ke ticket_resolutions). Pemakaian ganda ini membuat riwayat reviewer —
 * yang filter-nya `reviewed_by_me` tanpa membedakan review_type — ikut
 * menampilkan tiket yang hanya pernah di-approve user sebagai approver.
 *
 * Nama baru memisahkan jelas: APPROVAL_INITIAL & APPROVAL_FINAL adalah
 * keputusan approver; RESOLUTION tetap dipesan untuk resolusi handler.
 *
 * Catatan: pada saat migrasi ini ditulis, tabel ticket_resolutions masih
 * kosong (fitur resolusi handler belum digunakan), jadi seluruh log
 * RESOLUTION yang ada pasti jejak ApprovalController::decide.
 *
 * Teknis: enum diganti varchar(32) karena MySQL strict mode mengubah
 * warning "Data truncated" menjadi exception saat ALTER enum mempertahankan
 * nilai lama — varchar menghindari masalah itu sekaligus lebih fleksibel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_logs', function (Blueprint $table): void {
            $table->string('review_type', 32)->change();
        });

        DB::table('review_logs')
            ->where('review_type', 'FINAL_CLOSURE')
            ->update(['review_type' => 'APPROVAL_FINAL']);

        DB::table('review_logs')
            ->where('review_type', 'RESOLUTION')
            ->update(['review_type' => 'APPROVAL_INITIAL']);
    }

    public function down(): void
    {
        DB::table('review_logs')
            ->where('review_type', 'APPROVAL_INITIAL')
            ->update(['review_type' => 'RESOLUTION']);

        DB::table('review_logs')
            ->where('review_type', 'APPROVAL_FINAL')
            ->update(['review_type' => 'FINAL_CLOSURE']);

        Schema::table('review_logs', function (Blueprint $table): void {
            $table->enum('review_type', ['INITIAL', 'RESOLUTION', 'FINAL_CLOSURE'])->change();
        });
    }
};
