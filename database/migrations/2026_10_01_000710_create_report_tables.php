<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->tenantRoot();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('format', 8);
            $table->string('status', 16);
            $table->jsonb('filters');
            $table->timestampTz('cutoff_at');
            $table->jsonb('manifest')->default('{}');
            $table->string('disk', 32)->nullable();
            $table->string('key')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestampsTz();
        });

        Schema::create('report_schedules', function (Blueprint $table) {
            $table->tenantRoot();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('frequency', 16);
            $table->string('format', 8)->default('csv');
            $table->string('timezone', 64);
            $table->jsonb('filters');
            $table->jsonb('recipients')->default('[]');
            $table->timestampTz('next_run_at');
            $table->timestampTz('last_period_key_at')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestampsTz();
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->tenantRoot();
            $table->tenantForeign('event_id', 'events', nullable: true);
            $table->tenantForeign('report_id', 'reports', nullable: true);
            $table->string('channel', 16);
            $table->string('destination_ref');
            $table->string('dedupe_key', 128)->unique();
            $table->string('status', 16);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('next_retry_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->string('subject_type', 64)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->jsonb('redacted_changes')->default('{}');
            $table->timestampTz('created_at');
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('report_schedules');
        Schema::dropIfExists('reports');
    }
};
