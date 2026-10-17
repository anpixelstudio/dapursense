<?php

namespace App\Domain\Video;

use Symfony\Component\Process\Process;

/**
 * FFmpeg/ffprobe are always invoked with an argument list (never a shell string),
 * generated paths and a timeout.
 */
class MediaTools
{
    /**
     * @return array{duration_ms:int,width:int,height:int,fps:float,codec:string,rotation:int,container:string,pix_fmt:?string}
     */
    public function probe(string $path): array
    {
        $process = new Process([
            config('kitchen.binaries.ffprobe'), '-v', 'error', '-print_format', 'json',
            '-show_streams', '-show_format', $path,
        ]);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new VideoRejected('corrupt_file', 'File could not be read as video. Ensure the file is not corrupt.');
        }

        $data = json_decode($process->getOutput(), true) ?: [];
        $video = collect($data['streams'] ?? [])->firstWhere('codec_type', 'video');

        if (! $video || ($video['disposition']['attached_pic'] ?? 0) === 1) {
            throw new VideoRejected('no_video_stream', 'File has no video stream.');
        }

        $duration = (float) ($video['duration'] ?? $data['format']['duration'] ?? 0);

        if ($duration <= 0) {
            throw new VideoRejected('unknown_duration', 'Video duration could not be determined; the file may be corrupt.');
        }

        $rotation = (int) ($video['tags']['rotate'] ?? 0);
        foreach ($video['side_data_list'] ?? [] as $side) {
            if (isset($side['rotation'])) {
                $rotation = (int) $side['rotation'];
            }
        }

        return [
            'duration_ms' => (int) round($duration * 1000),
            'width' => (int) ($video['width'] ?? 0),
            'height' => (int) ($video['height'] ?? 0),
            'fps' => $this->fps($video['avg_frame_rate'] ?? $video['r_frame_rate'] ?? '0/1'),
            'codec' => (string) ($video['codec_name'] ?? 'unknown'),
            'rotation' => (($rotation % 360) + 360) % 360,
            'container' => (string) ($data['format']['format_name'] ?? ''),
            'pix_fmt' => $video['pix_fmt'] ?? null,
        ];
    }

    /**
     * Browser-safe H.264 MP4 without audio. Streams are copied only when no
     * re-encode is needed; rotation is baked in so overlay coordinates match.
     *
     * @param  array{codec:string,rotation:int,container:string,pix_fmt:?string}  $probe
     */
    public function makePreview(string $input, string $output, array $probe): bool
    {
        $copyable = in_array($probe['codec'], config('kitchen.upload.browser_codecs'), true)
            && $probe['rotation'] === 0
            && $probe['pix_fmt'] === 'yuv420p'
            && str_contains($probe['container'], 'mp4');

        $codec = $copyable
            ? ['-c:v', 'copy']
            : ['-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23', '-pix_fmt', 'yuv420p'];

        $this->run([
            config('kitchen.binaries.ffmpeg'), '-y', '-v', 'error', '-i', $input,
            '-map', '0:v:0', ...$codec, '-an', '-movflags', '+faststart', $output,
        ], 1800);

        return ! $copyable;
    }

    public function frame(string $input, string $output, int $atMs): void
    {
        $this->run([
            config('kitchen.binaries.ffmpeg'), '-y', '-v', 'error', '-ss', sprintf('%.3f', $atMs / 1000),
            '-i', $input, '-frames:v', '1', '-q:v', '3', $output,
        ], 60);
    }

    /** @param list<string> $command */
    private function run(array $command, int $timeout): void
    {
        $process = new Process($command);
        $process->setTimeout($timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new VideoRejected('transcode_failed', 'Video could not be processed by FFmpeg: '.str($process->getErrorOutput())->limit(300));
        }
    }

    private function fps(string $rate): float
    {
        [$num, $den] = array_pad(explode('/', $rate), 2, '1');

        return (float) $den > 0 ? round((float) $num / (float) $den, 3) : 0.0;
    }
}
