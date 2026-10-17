<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('timezone', 64)->default('Asia/Jakarta');
            $table->jsonb('settings')->default('{}');
            $table->string('status', 16)->default('active');
            $table->timestampsTz();
        });

        Schema::create('tenant_memberships', function (Blueprint $table) {
            $table->tenantRoot();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('role', 16);
            $table->string('status', 16)->default('active');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'user_id']);
        });

        Schema::create('invitations', function (Blueprint $table) {
            $table->tenantRoot();
            $table->string('email');
            $table->string('role', 16);
            $table->string('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('properties', function (Blueprint $table) {
            $table->tenantRoot();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('timezone', 64)->default('Asia/Jakarta');
            $table->timestampsTz();
        });

        Schema::create('kitchens', function (Blueprint $table) {
            $table->tenantRoot();
            $table->tenantForeign('property_id', 'properties');
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchens');
        Schema::dropIfExists('properties');
        Schema::dropIfExists('invitations');
        Schema::dropIfExists('tenant_memberships');
        Schema::dropIfExists('tenants');
    }
};
