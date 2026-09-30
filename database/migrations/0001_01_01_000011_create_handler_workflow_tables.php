<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->integer('revision_no');
            // The user revising the ticket (could be the reporter editing it)
            $table->foreignId('revised_by_user_id')->constrained('users');
            $table->json('changes');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['ticket_id', 'revision_no']);
        });

        Schema::create('ticket_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            // REVIEWER and APPROVER are tracked in review_logs, assignments are only for working on the ticket
            $table->enum('assignment_type', ['UNIT', 'HANDLER']);

            $table->foreignId('assigned_to_employee_id')->constrained('employee_profiles');
            $table->foreignId('assigned_by_employee_id')->nullable()->constrained('employee_profiles')->nullOnDelete();
            $table->foreignId('assigned_to_department_id')->nullable()->constrained('departments')->nullOnDelete();

            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamp('unassigned_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['ticket_id', 'assignment_type', 'is_active']);
        });

        Schema::create('handler_progress_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained('ticket_assignments')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->constrained('users');
            $table->text('note');
            // attachments are now polymorphic in ticket_attachments, so we don't need a json column here anymore,
            // but we can keep it if we want to store simple references or just drop it. We'll drop it in favor of the polymorphic relation.
            $table->boolean('is_internal')->default(false);
            $table->timestamps();
        });

        Schema::create('ticket_resolutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('handler_assignment_id')->constrained('ticket_assignments');
            $table->foreignId('submitted_by_user_id')->constrained('users');
            $table->integer('resolution_no');
            $table->text('summary');
            $table->text('detail');
            // Attachments handled by polymorphic relation in ticket_attachments
            $table->timestamp('submitted_at')->useCurrent();
            $table->timestamp('reviewed_at')->nullable();
            $table->enum('review_decision', ['PENDING', 'APPROVED', 'REJECTED'])->default('PENDING');
            $table->timestamps();
            $table->unique(['ticket_id', 'resolution_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_resolutions');
        Schema::dropIfExists('handler_progress_entries');
        Schema::dropIfExists('ticket_assignments');
        Schema::dropIfExists('ticket_revisions');
    }
};
