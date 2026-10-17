<?php

namespace App\Domain\Video;

use App\Enums\VideoStatus;
use App\Models\VideoAsset;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ValidateVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 2400;

    public function __construct(public string $videoId) {}

    public function handle(MediaTools $tools): void
    {
        $video = VideoAsset::query()->withoutGlobalScopes()->find($this->videoId);

        if (! $video || $video->status !== VideoStatus::Validating) {
            return;
        }

        $disk = Storage::disk($video->original_disk);

        try {
            $probe = $tools->probe($disk->path($video->original_key));

            if ($probe['duration_ms'] > config('kitchen.upload.max_duration_ms')) {
                throw new VideoRejected('too_long', sprintf(
                    'Video duration %d minute(s) exceeds the %d minute limit.',
                    ceil($probe['duration_ms'] / 60000),
                    config('kitchen.upload.max_duration_ms') / 60000,
                ));
            }

            if ($probe['width'] < 160 || $probe['height'] < 120) {
                throw new VideoRejected('resolution_too_small', 'Video resolution is too small for analysis.');
            }

            $previewKey = $video->directory().'/preview.mp4';
            $tools->makePreview($disk->path($video->original_key), $disk->path($previewKey), $probe);
            $preview = $tools->probe($disk->path($previewKey));
            $tools->frame($disk->path($previewKey), $disk->path($video->referenceFrameKey()), min(1000, intdiv($preview['duration_ms'], 2)));

            $video->update([
                'duration_ms' => $preview['duration_ms'],
                'width' => $preview['width'],
                'height' => $preview['height'],
                'fps' => $probe['fps'],
                'codec' => $probe['codec'],
                'rotation' => $probe['rotation'],
                'preview_key' => $previewKey,
                'status' => VideoStatus::Ready,
                'error_code' => null,
                'error_message' => null,
            ]);
        } catch (VideoRejected $e) {
            $video->update(['status' => VideoStatus::Rejected, 'error_code' => $e->errorCode, 'error_message' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('video.validation_failed', ['video_id' => $video->id, 'tenant_id' => $video->tenant_id, 'error' => $e->getMessage()]);
            $video->update(['status' => VideoStatus::Rejected, 'error_code' => 'processing_failed', 'error_message' => 'Video processing failed. Try uploading again.']);
        }
    }
}
