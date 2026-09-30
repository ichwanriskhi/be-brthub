<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Users table now serves as the central identity for BRTHub.
     * It mirrors data from the Auth Service for authenticated users,
     * but also allows creating "local" users for walk-in reporters
     * (customers who report via WhatsApp/Email without an account).
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('auth_service_uuid')->nullable()->unique(); // Canonical user UUID from Auth Service
            $table->string('full_name'); // Name of the person, synced from Auth Service or filled locally for walk-ins
            $table->string('email')->nullable()->unique();
            $table->string('phone_number')->nullable()->unique();
            $table->text('address')->nullable(); // Physical address, mostly for customers/reporters

            $table->boolean('is_active')->default(true);
            $table->string('source')->default('auth_service'); // auth_service, local (walk-in)
            $table->timestamp('last_synced_at')->nullable(); // Last time this row was synced from Auth Service
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
