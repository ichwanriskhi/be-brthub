<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->constrained('users');
            // ATTACHMENT type removed because attachments are handled via polymorphic ticket_attachments relation
            $table->enum('interaction_type', ['CHAT', 'NOTE', 'SYSTEM']);
            $table->text('content')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ticket_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('activity_type');
            $table->text('description');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamps();
        });

        Schema::create('ticket_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('related_ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->enum('relation_type', ['RECURRING_OF', 'RELATED_TO', 'FOLLOW_UP_OF']);
            $table->timestamps();
            $table->unique(['ticket_id', 'related_ticket_id', 'relation_type'], 'uk_ticket_relation');
        });

        // Attachments are now polymorphic. They can be attached to tickets directly,
        // ticket_interactions (chats), handler_progress_entries, or ticket_resolutions.
        Schema::create('ticket_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->morphs('attachable'); // attachable_type, attachable_id
            $table->string('file_name');
            $table->string('file_path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->foreignId('uploaded_by_user_id')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_attachments');
        Schema::dropIfExists('ticket_relations');
        Schema::dropIfExists('ticket_activities');
        Schema::dropIfExists('ticket_interactions');
    }
};
