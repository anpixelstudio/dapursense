<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, array<string, string>> */
    private array $checks = [
        'tenant_memberships' => [
            'role_known' => "role IN ('owner','supervisor','operator','viewer')",
            'status_known' => "status IN ('active','suspended')",
        ],
        'media_sources' => ['type_known' => "type IN ('upload','rtsp')"],
        'video_assets' => [
            'status_known' => "status IN ('UPLOADING','VALIDATING','READY','REJECTED')",
            'duration_nonnegative' => 'duration_ms IS NULL OR duration_ms >= 0',
        ],
        'zones' => ['type_known' => "type IN ('prep','stove','sink','cleaning','restricted')"],
        'ai_models' => [
            'validation_known' => "validation_status IN ('DRAFT','VALIDATED','ACTIVE')",
            'scope_tenant' => "(scope = 'global' AND tenant_id IS NULL) OR (scope = 'tenant' AND tenant_id IS NOT NULL)",
        ],
        'analysis_runs' => [
            'status_known' => "status IN ('QUEUED','RUNNING','SUCCEEDED','FAILED','CANCELLED')",
            'mode_known' => "mode IN ('actual','simulation')",
            'progress_range' => 'progress BETWEEN 0 AND 100',
            'processed_nonnegative' => 'processed_ms >= 0',
        ],
        'ingestion_batches' => ['status_known' => "status IN ('accepted','rejected')"],
        'person_tracks' => ['time_order' => 'first_ms >= 0 AND last_ms >= first_ms'],
        'activity_segments' => [
            'time_order' => 'start_ms >= 0 AND end_ms >= start_ms',
            'score_range' => 'score IS NULL OR score BETWEEN 0 AND 1',
        ],
        'ppe_assessments' => [
            'time_order' => 'start_ms >= 0 AND end_ms >= start_ms',
            'state_known' => "state IN ('present','missing','unknown')",
            'score_range' => 'score IS NULL OR score BETWEEN 0 AND 1',
            'visibility_range' => 'visibility IS NULL OR visibility BETWEEN 0 AND 1',
        ],
        'events' => [
            'time_order' => 'first_ms IS NULL OR last_ms IS NULL OR (first_ms >= 0 AND last_ms >= first_ms)',
            'review_known' => "review_status IN ('OPEN','ACKNOWLEDGED','CONFIRMED','DISMISSED','RESOLVED')",
            'category_known' => "category IN ('violation','system','review_needed')",
            'revision_positive' => 'revision >= 1',
            'peak_range' => 'peak_score IS NULL OR peak_score BETWEEN 0 AND 1',
        ],
        'event_media' => [
            'kind_known' => "kind IN ('snapshot','clip','overlay')",
            'state_known' => "state IN ('PENDING','READY','FAILED','EXPIRED')",
        ],
        'reports' => [
            'format_known' => "format IN ('csv','pdf')",
            'status_known' => "status IN ('QUEUED','RUNNING','READY','FAILED','EXPIRED')",
        ],
    ];

    public function up(): void
    {
        foreach ($this->checks as $table => $constraints) {
            foreach ($constraints as $name => $expression) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$name} CHECK ({$expression})");
            }
        }

        Schema::table('events', function (Blueprint $table) {
            $table->index(['tenant_id', 'review_status', 'created_at']);
            $table->index(['tenant_id', 'source_id', 'occurred_at']);
            $table->index(['tenant_id', 'run_id', 'first_ms']);
        });
        Schema::table('analysis_runs', fn (Blueprint $table) => $table->index(['tenant_id', 'status', 'created_at']));
        Schema::table('activity_segments', fn (Blueprint $table) => $table->index(['tenant_id', 'run_id', 'start_ms']));
        Schema::table('ppe_assessments', fn (Blueprint $table) => $table->index(['tenant_id', 'run_id', 'track_id', 'item_code', 'start_ms']));
        Schema::table('reports', fn (Blueprint $table) => $table->index(['tenant_id', 'created_at']));
        Schema::table('notification_deliveries', fn (Blueprint $table) => $table->index(['status', 'next_retry_at']));
        Schema::table('ingestion_batches', fn (Blueprint $table) => $table->index(['tenant_id', 'run_id', 'received_at']));
        Schema::table('video_assets', fn (Blueprint $table) => $table->index(['tenant_id', 'status', 'created_at']));
    }

    public function down(): void
    {
        foreach ($this->checks as $table => $constraints) {
            foreach (array_keys($constraints) as $name) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_{$name}");
            }
        }
    }
};
