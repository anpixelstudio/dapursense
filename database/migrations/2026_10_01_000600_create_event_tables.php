<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->tenantRoot();
            $table->tenantForeign('run_id', 'analysis_runs', nullable: true);
            $table->tenantForeign('source_id', 'media_sources');
            $table->tenantForeign('track_id', 'person_tracks', nullable: true);
            $table->string('type', 64);
            $table->string('category', 16);
            $table->string('severity', 16);
            $table->string('lifecycle', 24);
            $table->string('review_status', 16)->default('OPEN');
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('last_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('last_reviewed_at')->nullable();
            $table->bigInteger('first_ms')->nullable();
            $table->bigInteger('last_ms')->nullable();
            $table->timestampTz('occurred_at')->nullable();
            $table->decimal('peak_score', 5, 4)->nullable();
            $table->jsonb('evidence')->default('{}');
            $table->boolean('evidence_hold')->default(false);
            $table->boolean('is_simulation')->default(false);
            $table->string('dedupe_key', 128);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'dedupe_key']);
        });

        Schema::create('event_reviews', function (Blueprint $table) {
            $table->tenantRoot();
            $table->tenantForeign('event_id', 'events');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status', 16);
            $table->string('to_status', 16);
            $table->unsignedInteger('event_revision');
            $table->string('reason_code', 32)->nullable();
            $table->text('reason')->nullable();
            $table->text('action_note')->nullable();
            $table->timestampTz('created_at');
        });

        Schema::create('event_media', function (Blueprint $table) {
            $table->tenantRoot();
            $table->tenantForeign('event_id', 'events');
            $table->string('kind', 16);
            $table->string('disk', 32);
            $table->string('key')->nullable();
            $table->string('state', 16);
            $table->bigInteger('start_ms')->nullable();
            $table->bigInteger('end_ms')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestampsTz();
            $table->unique(['event_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_media');
        Schema::dropIfExists('event_reviews');
        Schema::dropIfExists('events');
    }
};
