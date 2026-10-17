<?php

use App\Domain\Organization\TenantProvisioner;
use App\Enums\EventCategory;
use App\Enums\Role;
use App\Enums\VideoStatus;
use App\Models\AiModel;
use App\Models\AnalysisRun;
use App\Models\AnomalyEvent;
use App\Models\Kitchen;
use App\Models\MediaSource;
use App\Models\Property;
use App\Models\RuleSet;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\VideoAsset;
use App\Support\TenantContext;
use Database\Seeders\SystemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Install');

/**
 * Creates a user with the given role in a tenant (a new tenant when none is given).
 */
function tenantUser(Role $role = Role::Owner, ?Tenant $tenant = null): User
{
    $user = User::factory()->create();

    if ($tenant === null) {
        $owner = $role === Role::Owner ? $user : User::factory()->create();
        $tenant = app(TenantProvisioner::class)->create($owner, 'Hotel '.Str::random(5));

        if ($role === Role::Owner) {
            return $user;
        }
    }

    TenantMembership::query()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role]);

    return $user;
}

function tenantOf(User $user): Tenant
{
    return TenantMembership::query()->where('user_id', $user->id)->firstOrFail()->tenant;
}

/** @return array{property: Property, kitchen: Kitchen, source: MediaSource} */
function makeLocation(Tenant $tenant): array
{
    $property = Property::query()->create(['tenant_id' => $tenant->id, 'name' => 'Properti', 'timezone' => 'Asia/Jakarta']);
    $kitchen = Kitchen::query()->create(['tenant_id' => $tenant->id, 'property_id' => $property->id, 'name' => 'Dapur Utama']);
    $source = MediaSource::query()->create(['tenant_id' => $tenant->id, 'kitchen_id' => $kitchen->id, 'name' => 'Kamera 1', 'type' => 'upload']);

    return compact('property', 'kitchen', 'source');
}

function makeReadyVideo(MediaSource $source, array $attributes = []): VideoAsset
{
    $video = new VideoAsset($attributes + [
        'tenant_id' => $source->tenant_id,
        'source_id' => $source->id,
        'original_name' => 'dapur.mp4',
        'original_disk' => 'local',
        'bytes' => 1000,
        'duration_ms' => 20000,
        'width' => 640,
        'height' => 360,
        'fps' => 25,
        'codec' => 'h264',
        'status' => VideoStatus::Ready,
    ]);
    $video->id = (string) Str::uuid();
    $video->original_key ??= $video->directory().'/original.mp4';
    $video->preview_key ??= $video->directory().'/preview.mp4';
    $video->save();

    return $video;
}

function seedModels(): void
{
    (new SystemSeeder)->run();
}

function simulatorModel(): AiModel
{
    seedModels();

    return AiModel::query()->where('name', 'anpixel-simulator')->firstOrFail();
}

function realModel(): AiModel
{
    seedModels();

    return AiModel::query()->where('name', 'opencv-hog-person')->firstOrFail();
}

function defaultRuleSet(Tenant $tenant): RuleSet
{
    return RuleSet::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->with('activeRevision')->firstOrFail();
}

/**
 * A RUNNING run with a known worker token, as if dispatched to the worker.
 *
 * @return array{0: AnalysisRun, 1: string}
 */
function runningRun(VideoAsset $video, string $mode = 'simulation', array $zones = []): array
{
    $model = $mode === 'simulation' ? simulatorModel() : realModel();
    $tenant = Tenant::query()->findOrFail($video->tenant_id);
    $revision = defaultRuleSet($tenant)->activeRevision;
    $token = Str::random(48);

    $run = new AnalysisRun([
        'tenant_id' => $video->tenant_id,
        'source_id' => $video->source_id,
        'video_asset_id' => $video->id,
        'model_id' => $model->id,
        'rule_revision_id' => $revision->id,
        'mode' => $mode,
        'status' => 'RUNNING',
        'phase' => 'inference',
        'app_version' => 'test',
        'model_sha256' => $model->sha256,
        'rules_sha256' => $revision->checksum,
        'worker_token_hash' => hash('sha256', $token),
        'heartbeat_at' => now(),
        'started_at' => now(),
    ]);
    $run->id = (string) Str::uuid();
    $run->input_snapshot = [
        'video' => ['duration_ms' => $video->duration_ms, 'recorded_at' => $video->recorded_at?->toIso8601String()],
        'zones' => $zones,
        'mode' => $mode,
    ];
    $run->save();

    return [$run, $token];
}

/** Generates a short real H.264 test video with FFmpeg. */
function fixtureVideo(string $name = 'sample.mp4', int $seconds = 2, string $codecArgs = '-c:v libx264 -pix_fmt yuv420p'): string
{
    $dir = storage_path('framework/testing/fixtures');
    @mkdir($dir, 0777, true);
    $path = "{$dir}/{$name}";

    if (! file_exists($path)) {
        $process = new Process([
            'ffmpeg', '-y', '-v', 'error', '-f', 'lavfi', '-i', "testsrc=size=320x240:rate=10:duration={$seconds}",
            ...explode(' ', $codecArgs), $path,
        ]);
        $process->mustRun();
    }

    return $path;
}

function withTenant(Tenant $tenant): void
{
    app(TenantContext::class)->setTenant($tenant->id);
}

/** A valid worker batch payload for the run, with optional overrides. */
function batchPayload(AnalysisRun $run, int $sequence = 1, array $overrides = []): array
{
    return array_replace([
        'schema_version' => config('kitchen.worker.contract_version'),
        'attempt' => $run->attempt ?? 1,
        'batch_sequence' => $sequence,
        'model_sha256' => $run->model_sha256,
        'rules_sha256' => $run->rules_sha256,
        'progress' => ['processed_ms' => 5000 * $sequence, 'phase' => 'inference'],
        'tracks' => [],
        'activity_segments' => [],
        'ppe_assessments' => [],
        'events' => [],
    ], $overrides);
}

function ppeEvent(AnalysisRun $run, array $overrides = []): array
{
    return array_replace([
        'type' => 'ppe_missing',
        'category' => 'violation',
        'severity' => 'medium',
        'lifecycle' => 'active',
        'track_key' => '1',
        'episode_start_ms' => 2000,
        'first_ms' => 2000,
        'last_ms' => 6000,
        'peak_score' => 0.8,
        'evidence' => ['bbox_xyxy_normalized' => [0.1, 0.1, 0.4, 0.9], 'item_code' => 'hair_cover'],
        'media' => [
            ['kind' => 'snapshot', 'state' => 'READY', 'key' => $run->directory().'/evidence/e1.jpg', 'start_ms' => 2000, 'end_ms' => 2000],
        ],
    ], $overrides);
}

function makeEvent(AnalysisRun $run, array $attributes = []): AnomalyEvent
{
    return AnomalyEvent::query()->withoutGlobalScopes()->create($attributes + [
        'tenant_id' => $run->tenant_id,
        'run_id' => $run->id,
        'source_id' => $run->source_id,
        'type' => 'ppe_missing',
        'category' => EventCategory::Violation,
        'severity' => 'medium',
        'lifecycle' => 'closed',
        'first_ms' => 1000,
        'last_ms' => 5000,
        'is_simulation' => $run->mode === 'simulation',
        'dedupe_key' => (string) Str::uuid(),
    ]);
}
