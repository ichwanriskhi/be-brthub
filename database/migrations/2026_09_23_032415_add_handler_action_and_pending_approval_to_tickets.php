<?php

use App\Models\TicketStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // Instruksi reviewer untuk handler (klaim distribusi & pengiriman)
            $table->string('handler_action_code', 50)->nullable()->after('approval_type');
        });

        // Status PENDING_APPROVAL: reviewer sudah route, menunggu approver
        TicketStatus::firstOrCreate(
            ['code' => 'PENDING_APPROVAL'],
            ['name' => 'Pending Approval'],
        );
    }

    public function down(): void
    {
        TicketStatus::where('code', 'PENDING_APPROVAL')->delete();

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('handler_action_code');
        });
    }
};
