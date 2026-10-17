<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->tenantRoot();
            $table->tenantForeign('kitchen_id', 'kitchens', nullable: true);
            $table->tenantForeign('source_id', 'media_sources', nullable: true);
            $table->string('name');
            $table->string('kind', 16);
            $table->string('check_type', 16);
            $table->string('host')->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->string('target', 32)->nullable();
            $table->unsignedInteger('interval_seconds')->default(60);
            $table->unsignedSmallInteger('failure_threshold')->default(3);
            $table->boolean('paused')->default(false);
            $table->string('status', 16)->default('unknown');
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->integer('last_latency_ms')->nullable();
            $table->string('last_error', 64)->nullable();
            $table->timestampTz('last_checked_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('status_changed_at')->nullable();
            $table->string('heartbeat_token_hash', 64)->nullable()->unique();
            $table->jsonb('meta')->nullable();
            $table->timestampsTz();
            $table->index(['paused', 'last_checked_at']);
            $table->unique(['tenant_id', 'check_type', 'target']);
        });

        Schema::create('device_incidents', function (Blueprint $table) {
            $table->tenantRoot();
            $table->tenantForeign('device_id', 'devices');
            $table->tenantForeign('event_id', 'events', nullable: true);
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();
            $table->string('reason', 64)->nullable();
            $table->index(['tenant_id', 'started_at']);
            $table->index(['device_id', 'ended_at']);
        });

        // One row per device per hour instead of one per check keeps the table small at any check interval.
        Schema::create('device_stats_hourly', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->tenantForeign('device_id', 'devices');
            $table->timestampTz('hour');
            $table->unsignedInteger('checks')->default(0);
            $table->unsignedInteger('failures')->default(0);
            $table->unsignedBigInteger('latency_sum')->default(0);
            $table->unsignedInteger('latency_count')->default(0);
            $table->unsignedInteger('latency_max')->default(0);
            $table->primary(['device_id', 'hour']);
            $table->index(['tenant_id', 'hour']);
        });

        // Device anomalies are not tied to a video source.
        Schema::table('events', function (Blueprint $table) {
            $table->uuid('source_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_stats_hourly');
        Schema::dropIfExists('device_incidents');
        Schema::dropIfExists('devices');
    }
};
