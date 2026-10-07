<?php

use App\Models\TicketActivity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ganti `ticket_activities.description` untuk baris `PROGRESS` yang masih
 * menyimpan cuplikan 120 karakter dari catatan progres.
 *
 * Timeline kini hanya mencatat kejadian ("Progres pengerjaan ditambahkan."),
 * sedangkan isi lengkapnya ada di `handler_progress_entries` dan dirender lewat
 * card Riwayat Progres. Baris lama harus ikut dirapikan supaya tidak menampilkan
 * teks yang sudah tidak ada sumbernya di timeline.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('ticket_activities')
            ->where('activity_type', TicketActivity::TYPE_PROGRESS)
            ->where('description', '!=', TicketActivity::DESCRIPTION_PROGRESS)
            ->update(['description' => TicketActivity::DESCRIPTION_PROGRESS]);
    }

    public function down(): void
    {
        // Teks asli tidak disimpan di mana pun, jadi `down()` tidak bisa
        // memulihkannya. Kembalikan ke kondisi sebelum perubahan: tanpa
        // deskripsi generik, agar tidak menyesatkan.
        DB::table('ticket_activities')
            ->where('activity_type', TicketActivity::TYPE_PROGRESS)
            ->where('description', TicketActivity::DESCRIPTION_PROGRESS)
            ->update(['description' => 'Progres pengerjaan:']);
    }
};
