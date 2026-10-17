<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analysis_runs', function (Blueprint $table) {
            $table->tenantRoot();
            $table->tenantForeign('source_id', 'media_sources');
            $table->tenantForeign('video_asset_id', 'video_assets', nullable: true);
            $table->foreignUuid('model_id')->constrained('ai_models')->restrictOnDelete();
            $table->tenantForeign('rule_revision_id', 'rule_revisions');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('mode', 16);
            $table->string('status', 16);
            $table->string('phase', 32)->nullable();
            $table->unsignedSmallInteger('progress')->default(0);
            $table->bigInteger('processed_ms')->default(0);
            $table->jsonb('input_snapshot');
            $table->jsonb('result_manifest')->nullable();
            $table->string('app_version', 64);
            $table->string('model_sha256', 64);
            $table->string('rules_sha256', 64);
            $table->string('worker_token_hash', 64)->nullable();
            $table->unsignedInteger('attempt')->default(1);
            $table->unsignedInteger('last_batch_sequence')->default(0);
            $table->timestampTz('heartbeat_at')->nullable();
            $table->timestampTz('cancel_requested_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('ingestion_batches', function (Blueprint $table) {
            $table->tenantRoot();
            $table->tenantForeign('run_id', 'analysis_runs');
            $table->unsignedInteger('attempt');
            $table->unsignedInteger('batch_sequence');
            $table->string('payload_sha256', 64);
            $table->unsignedInteger('item_count');
            $table->string('status', 16);
            $table->string('error_code', 64)->nullable();
            $table->timestampTz('received_at');
            $table->unique(['run_id', 'attempt', 'batch_sequence']);
        });

        Schema::create('person_tracks', function (Blueprint $table) {
            $table->tenantRoot();
            $table->tenantForeign('run_id', 'analysis_runs');
            $table->string('track_key', 32);
            $table->bigInteger('first_ms');
            $table->bigInteger('last_ms');
            $table->timestampsTz();
            $table->unique(['run_id', 'track_key']);
        });

        Schema::create('activity_segments', function (Blueprint $table) {
            $table->tenantRoot();
            $table->tenantForeign('run_id', 'analysis_runs');
            $table->tenantForeign('track_id', 'person_tracks');
            $table->tenantForeign('zone_id', 'zones', nullable: true);
            $table->string('label', 32);
            $table->bigInteger('start_ms');
            $table->bigInteger('end_ms');
            $table->decimal('score', 5, 4)->nullable();
            $table->jsonb('evidence')->default('{}');
            $table->timestampsTz();
        });

        Schema::create('ppe_assessments', function (Blueprint $table) {
            $table->tenantRoot();
            $table->tenantForeign('run_id', 'analysis_runs');
            $table->tenantForeign('track_id', 'person_tracks');
            $table->string('item_code', 32);
            $table->boolean('required');
            $table->string('state', 16);
            $table->bigInteger('start_ms');
            $table->bigInteger('end_ms');
            $table->decimal('score', 5, 4)->nullable();
            $table->decimal('visibility', 5, 4)->nullable();
            $table->string('reason', 64)->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ppe_assessments');
        Schema::dropIfExists('activity_segments');
        Schema::dropIfExists('person_tracks');
        Schema::dropIfExists('ingestion_batches');
        Schema::dropIfExists('analysis_runs');
    }
};
