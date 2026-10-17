<?php

namespace App\Http\Controllers;

use App\Domain\System\StorageMonitor;
use App\Domain\Video\ValidateVideoJob;
use App\Enums\Permission;
use App\Enums\VideoStatus;
use App\Models\AiModel;
use App\Models\AnalysisRun;
use App\Models\MediaSource;
use App\Models\RuleSet;
use App\Models\VideoAsset;
use App\Models\Zone;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class VideoController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizePermission(Permission::ViewData);

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(VideoStatus::class)],
            'source_id' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $videos = VideoAsset::query()
            ->with(['source:id,name,kitchen_id', 'source.kitchen:id,name', 'uploader:id,name'])
            ->withCount('runs')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['source_id'] ?? null, fn ($q, $s) => $q->where('source_id', $s))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('original_name', 'ilike', '%'.$s.'%'))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('videos/index', [
            'videos' => $videos,
            'filters' => $filters,
            'sources' => MediaSource::query()->where('type', 'upload')->where('status', 'active')
                ->with('kitchen:id,name')->orderBy('name')->get(['id', 'name', 'kitchen_id']),
            'limits' => [
                'max_bytes' => config('kitchen.upload.max_bytes'),
                'max_duration_minutes' => config('kitchen.upload.max_duration_ms') / 60000,
                'extensions' => config('kitchen.upload.extensions'),
            ],
        ]);
    }

    public function store(Request $request, StorageMonitor $storage): RedirectResponse
    {
        $this->authorizePermission(Permission::UploadVideo);

        if ($storage->isBlocked()) {
            return back()->withErrors(['video' => 'Penyimpanan hampir penuh; upload baru diblokir sampai ruang dibebaskan. Bukti lama tidak dihapus otomatis.']);
        }

        $maxKb = intdiv(config('kitchen.upload.max_bytes'), 1024);
        $validated = $request->validate([
            'source_id' => ['required', 'uuid', Rule::exists('media_sources', 'id')
                ->where('tenant_id', $this->tenantId())->where('type', 'upload')->where('status', 'active')],
            'video' => ['required', 'file', 'max:'.$maxKb, 'extensions:'.implode(',', config('kitchen.upload.extensions'))],
            'recorded_at' => ['nullable', 'date'],
            'recorded_timezone' => ['nullable', 'required_with:recorded_at', 'timezone:all'],
        ], [
            'video.max' => 'Ukuran file melebihi batas '.round(config('kitchen.upload.max_bytes') / 1048576).' MB.',
            'video.extensions' => 'Format tidak didukung. Gunakan MP4, MOV, atau MKV.',
        ]);

        $file = $validated['video'];
        $video = new VideoAsset([
            'tenant_id' => $this->tenantId(),
            'source_id' => $validated['source_id'],
            'uploaded_by' => $request->user()->id,
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'original_disk' => config('kitchen.storage_disk'),
            'bytes' => $file->getSize(),
            'status' => VideoStatus::Validating,
            'recorded_at' => $validated['recorded_at'] ?? null,
            'recorded_timezone' => $validated['recorded_timezone'] ?? null,
        ]);
        $video->id = (string) Str::uuid();

        $key = $file->storeAs($video->directory(), 'original.'.strtolower($file->getClientOriginalExtension()), $video->original_disk);
        $video->original_key = $key;
        $video->checksum = hash_file('sha256', Storage::disk($video->original_disk)->path($key));
        $video->save();

        ValidateVideoJob::dispatch($video->id);
        Audit::log('video.uploaded', $video, ['name' => $video->original_name, 'bytes' => $video->bytes]);
        $this->toast('Video diunggah dan sedang divalidasi.');

        return redirect()->route('videos.show', $video);
    }

    public function show(VideoAsset $video): Response
    {
        $this->authorizePermission(Permission::ViewData, $video);
        $video->load(['source.kitchen.property', 'uploader:id,name']);

        return Inertia::render('videos/show', [
            'video' => $video,
            'zones' => Zone::query()->where('source_id', $video->source_id)->where('active', true)->orderBy('name')->get(),
            'runs' => AnalysisRun::query()->where('video_asset_id', $video->id)
                ->with(['model:id,name,version', 'ruleRevision:id,version,rule_set_id', 'ruleRevision.ruleSet:id,name', 'requester:id,name'])
                ->orderByDesc('created_at')->get(),
            'models' => AiModel::query()->availableTo($this->tenantId())
                ->get(['id', 'name', 'version', 'runtime', 'capabilities', 'validation_status']),
            'ruleSets' => RuleSet::query()->whereNotNull('active_revision_id')->with('activeRevision:id,version')
                ->orderBy('name')->get(['id', 'name', 'active_revision_id']),
            'otherSources' => MediaSource::query()->whereKeyNot($video->source_id)->orderBy('name')->get(['id', 'name']),
            'reanalysisAvailableUntil' => $video->original_expires_at,
            'canReanalyze' => $video->canReanalyze(),
        ]);
    }

    public function status(VideoAsset $video): array
    {
        $this->authorizePermission(Permission::ViewData, $video);

        return $video->only(['id', 'status', 'error_code', 'error_message', 'duration_ms', 'width', 'height']);
    }

    public function update(Request $request, VideoAsset $video): RedirectResponse
    {
        $this->authorizePermission(Permission::UploadVideo, $video);

        $video->update($request->validate([
            'recorded_at' => ['nullable', 'date'],
            'recorded_timezone' => ['nullable', 'required_with:recorded_at', 'timezone:all'],
        ]));
        Audit::log('video.updated', $video, $video->getChanges());
        $this->toast('Informasi rekaman disimpan.');

        return back();
    }

    public function preview(VideoAsset $video): BinaryFileResponse
    {
        $this->authorizePermission(Permission::ViewData, $video);
        abort_if($video->preview_key === null, 404, 'Preview belum tersedia atau sudah kedaluwarsa.');

        return $this->privateFile($video->original_disk, $video->preview_key, 'video/mp4');
    }

    public function referenceFrame(VideoAsset $video): BinaryFileResponse
    {
        $this->authorizePermission(Permission::ViewData, $video);

        return $this->privateFile($video->original_disk, $video->referenceFrameKey(), 'image/jpeg');
    }

    public function destroy(VideoAsset $video): RedirectResponse
    {
        $this->authorizePermission(Permission::ManageSources, $video);

        if (AnalysisRun::query()->where('video_asset_id', $video->id)->exists()) {
            return back()->withErrors(['video' => 'Video sudah memiliki hasil analisis; file akan dihapus otomatis sesuai jadwal retensi.']);
        }

        DB::transaction(fn () => $video->delete());
        Storage::disk($video->original_disk)->deleteDirectory($video->directory());
        Audit::log('video.deleted', $video);
        $this->toast('Video dihapus.');

        return redirect()->route('videos.index');
    }

    private function privateFile(string $disk, string $key, string $mime): BinaryFileResponse
    {
        $storage = Storage::disk($disk);
        abort_unless($storage->exists($key), 404, 'File tidak ditemukan atau sudah kedaluwarsa.');

        return response()->file($storage->path($key), [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
