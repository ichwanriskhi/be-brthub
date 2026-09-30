<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // workflow_configs has been removed in favor of hardcoding routing logic in the backend (Service class).

        Schema::create('review_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('reviewer_employee_id')->constrained('employee_profiles');
            $table->enum('review_type', ['INITIAL', 'RESOLUTION', 'FINAL_CLOSURE']);
            $table->enum('decision', ['ROUTE', 'REQUEST_REWORK', 'APPROVE', 'REJECT', 'CLOSE']);

            $table->foreignId('destination_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('resolution_id')->nullable()->constrained('ticket_resolutions')->nullOnDelete();

            // Allow chaining reviews for an audit trail
            $table->foreignId('previous_review_id')->nullable()->constrained('review_logs')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamp('reviewed_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_logs');
    }
};
