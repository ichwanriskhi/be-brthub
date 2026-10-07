<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lini Produk tidak lagi dari tabel lokal `products`, melainkan dari Item
 * Group WANSIS/SAP (master tunggal di sana). Kode grup yang disimpan di
 * `ticket_vehicle_details.group_code` (string, nullable — kode numerik
 * upstream disimpan apa adanya sebagai string agar tidak terpotong).
 *
 * Data masih dummy (dev) sehingga tidak ada migrasi data: tabel di-drop,
 * kolom FK diganti kolom kode.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_vehicle_details', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropColumn('product_id');
        });

        Schema::table('ticket_vehicle_details', function (Blueprint $table) {
            $table->string('group_code', 50)->nullable()->after('ticket_id');
        });

        Schema::dropIfExists('products');
    }

    public function down(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('ticket_vehicle_details', function (Blueprint $table) {
            $table->dropColumn('group_code');
        });

        Schema::table('ticket_vehicle_details', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
        });
    }
};
