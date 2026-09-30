<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remove columns inherited from the legacy BTAS schema that are no longer
     * backed by any business logic:
     * - customers.external_code: canonical external identity already lives in
     *   users.auth_service_uuid, nothing reads or writes this column.
     * - employee_profiles.hire_date: never surfaced in the UI or used in routing.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('external_code');
        });

        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->dropColumn('hire_date');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('external_code')->nullable()->after('user_id');
        });

        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->date('hire_date')->nullable()->after('position_id');
        });
    }
};
