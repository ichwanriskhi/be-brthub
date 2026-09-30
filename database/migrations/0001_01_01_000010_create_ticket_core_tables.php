<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_no')->unique();

            // The person submitting the ticket (employee or customer)
            $table->foreignId('reporter_user_id')->constrained('users');

            // If submitted by an employee on behalf of a customer, record the customer here
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            $table->foreignId('category_id')->constrained('categories');
            $table->foreignId('ticket_type_id')->constrained('ticket_types');
            $table->foreignId('priority_id')->nullable()->constrained('priority_levels');
            $table->foreignId('status_id')->constrained('ticket_statuses');

            $table->string('subject', 255);
            $table->text('description');

            $table->enum('approval_type', ['DIREKSI', 'GENERAL_MANAGER', 'OPERATIONAL_MANAGER', 'DIVISION'])->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        // Detail table for vehicle-related data (if applicable to the ticket category)
        Schema::create('ticket_vehicle_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->unique()->constrained('tickets')->cascadeOnDelete();
            // Product line selected from master data
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            // Vehicle model typed or selected from external system data
            $table->string('vehicle_model')->nullable();
            $table->timestamps();
        });

        // Detail table for sales/SO-related data (e.g. for delivery claims)
        Schema::create('ticket_sales_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->unique()->constrained('tickets')->cascadeOnDelete();
            $table->string('so_number')->nullable();
            $table->string('sales_name')->nullable();
            // Claimed items json consolidated here for delivery claims
            $table->json('claimed_items')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_sales_details');
        Schema::dropIfExists('ticket_vehicle_details');
        Schema::dropIfExists('tickets');
    }
};
