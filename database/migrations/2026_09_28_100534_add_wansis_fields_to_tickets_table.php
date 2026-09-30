<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedInteger('wansis_report_id')->nullable()->after('destination_department_id');
            $table->timestamp('wansis_sent_at')->nullable()->after('wansis_report_id');
            $table->string('wansis_status', 20)->nullable()->after('wansis_sent_at');
            $table->text('wansis_error_message')->nullable()->after('wansis_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn([
                'wansis_report_id',
                'wansis_sent_at',
                'wansis_status',
                'wansis_error_message',
            ]);
        });
    }
};
