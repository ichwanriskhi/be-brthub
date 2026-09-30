<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Assignment UNIT (tiket diteruskan ke departemen) tidak langsung
        // memiliki handler — handler baru di-assign oleh unit itu sendiri.
        Schema::table('ticket_assignments', function (Blueprint $table) {
            $table->foreignId('assigned_to_employee_id')
                ->nullable()
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('ticket_assignments', function (Blueprint $table) {
            $table->foreignId('assigned_to_employee_id')
                ->nullable(false)
                ->change();
        });
    }
};
