<?php

use App\Domain\Video\MediaTools;
use App\Enums\Role;
use App\Enums\VideoStatus;
use App\Models\VideoAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->owner = tenantUser();
    $this->tenant = tenantOf($this->owner);
    $this->loc = makeLocation($this->tenant);
});

function uploadFixture(string $path, string $name = 'dapur.mp4'): UploadedFile
{
    return new UploadedFile($path, $name, null, null, true);
}

test('a valid H.264 upload is validated into a playable preview and reference frame', function () {
    $this->actingAs($this->owner)->post(route('videos.store'), [
        'source_id' => $this->loc['source']->id,
        'video' => uploadFixture(fixtureVideo()),
        'recorded_at' => '2026-09-30 08:00:00',
        'recorded_timezone' => 'Asia/Jakarta',
    ])->assertSessionHasNoErrors()->assertRedirect();

    $video = VideoAsset::query()->withoutGlobalScopes()->firstOrFail();

    expect($video->status)->toBe(VideoStatus::Ready)
        ->and($video->duration_ms)->toBeGreaterThanOrEqual(1900)
        ->and($video->width)->toBe(320)
        ->and($video->height)->toBe(240)
        ->and($video->checksum)->toHaveLength(64)
        ->and($video->tenant_id)->toBe($this->tenant->id)
        ->and($video->original_key)->toStartWith("tenants/{$this->tenant->id}/videos/{$video->id}/");

    Storage::disk('local')->assertExists([$video->preview_key, $video->referenceFrameKey()]);

    $this->actingAs($this->owner)->get(route('videos.preview', $video))
        ->assertOk()->assertHeader('Content-Type', 'video/mp4');
    $this->actingAs($this->owner)->get(route('videos.preview', $video), ['Range' => 'bytes=0-99'])
        ->assertStatus(206);
    $this->actingAs($this->owner)->get(route('videos.reference', $video))->assertOk();
});

test('a non browser codec is transcoded to H.264 for the preview', function () {
    $this->actingAs($this->owner)->post(route('videos.store'), [
        'source_id' => $this->loc['source']->id,
        'video' => uploadFixture(fixtureVideo('mpeg4.mkv', 2, '-c:v mpeg4'), 'kamera.mkv'),
    ])->assertSessionHasNoErrors();

    $video = VideoAsset::query()->withoutGlobalScopes()->firstOrFail();
    expect($video->status)->toBe(VideoStatus::Ready);

    $preview = app(MediaTools::class)->probe(Storage::disk('local')->path($video->preview_key));
    expect($preview['codec'])->toBe('h264');
});

test('a corrupt file is rejected with a readable reason', function () {
    $path = storage_path('framework/testing/fixtures/corrupt.mp4');
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, random_bytes(4096));

    $this->actingAs($this->owner)->post(route('videos.store'), [
        'source_id' => $this->loc['source']->id,
        'video' => uploadFixture($path),
    ])->assertSessionHasNoErrors();

    $video = VideoAsset::query()->withoutGlobalScopes()->firstOrFail();
    expect($video->status)->toBe(VideoStatus::Rejected)
        ->and($video->error_message)->not->toBeEmpty();
});

test('videos longer than the limit are rejected', function () {
    config(['kitchen.upload.max_duration_ms' => 1000]);

    $this->actingAs($this->owner)->post(route('videos.store'), [
        'source_id' => $this->loc['source']->id,
        'video' => uploadFixture(fixtureVideo()),
    ]);

    $video = VideoAsset::query()->withoutGlobalScopes()->firstOrFail();
    expect($video->status)->toBe(VideoStatus::Rejected)->and($video->error_code)->toBe('too_long');
});

test('unsupported extensions are rejected before storage', function () {
    $this->actingAs($this->owner)->post(route('videos.store'), [
        'source_id' => $this->loc['source']->id,
        'video' => UploadedFile::fake()->create('dapur.avi', 100),
    ])->assertSessionHasErrors('video');

    expect(VideoAsset::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('uploads are blocked when storage is almost full', function () {
    config(['kitchen.storage.block_percent' => 0]);

    $this->actingAs($this->owner)->post(route('videos.store'), [
        'source_id' => $this->loc['source']->id,
        'video' => uploadFixture(fixtureVideo()),
    ])->assertSessionHasErrors('video');

    expect(VideoAsset::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('uploads to a source from another tenant are rejected', function () {
    $foreign = makeLocation(tenantOf(tenantUser()));

    $this->actingAs($this->owner)->post(route('videos.store'), [
        'source_id' => $foreign['source']->id,
        'video' => uploadFixture(fixtureVideo()),
    ])->assertSessionHasErrors('source_id');
});

test('viewers cannot upload but operators can', function () {
    $viewer = tenantUser(Role::Viewer, $this->tenant);
    $operator = tenantUser(Role::Operator, $this->tenant);
    $payload = fn () => ['source_id' => $this->loc['source']->id, 'video' => uploadFixture(fixtureVideo())];

    $this->actingAs($viewer)->post(route('videos.store'), $payload())->assertForbidden();
    $this->actingAs($operator)->post(route('videos.store'), $payload())->assertRedirect();
});

test('video files are never served to other tenants', function () {
    $video = makeReadyVideo($this->loc['source']);
    $intruder = tenantUser();

    $this->actingAs($intruder)->get(route('videos.preview', $video))->assertNotFound();
});
