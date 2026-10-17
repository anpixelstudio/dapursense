<?php

namespace App\Domain\Analysis;

use App\Domain\Events\SystemEvents;
use App\Enums\EventCategory;
use App\Enums\Permission;
use App\Enums\RunStatus;
use App\Models\ActivitySegment;
use App\Models\AnalysisRun;
use App\Models\AnomalyEvent;
use App\Models\EventMedia;
use App\Models\IngestionBatch;
use App\Models\PersonTrack;
use App\Models\PpeAssessment;
use App\Models\TenantMembership;
use App\Notifications\EventsNeedReview;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class IngestionService
{
    public static function dedupeKey(AnalysisRun $run, ?string $trackKey, string $type, int $episodeStartMs): string
    {
        return hash('sha256', implode('|', [$run->id, $trackKey ?? '-', $type, $run->rule_revision_id, $episodeStartMs]));
    }

    /** @param array<string, mixed> $payload */
    public function ingest(AnalysisRun $run, array $payload, string $rawBody): IngestionResult
    {
        $sha = hash('sha256', $rawBody);

        return DB::transaction(function () use ($run, $payload, $sha) {
            $run = AnalysisRun::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($run->id);

            if ($guard = $this->guard($run, (int) $payload['attempt'])) {
                return $guard;
            }

            $sequence = (int) $payload['batch_sequence'];
            $existing = IngestionBatch::query()->withoutGlobalScopes()
                ->where('run_id', $run->id)->where('attempt', $run->attempt)->where('batch_sequence', $sequence)
                ->first();

            if ($existing) {
                if (hash_equals($existing->payload_sha256, $sha)) {
                    return IngestionResult::ok(['replayed' => true, 'last_batch_sequence' => $run->last_batch_sequence]);
                }

                $this->failRun($run, 'payload_conflict', 'Batch dengan sequence sama dikirim dengan isi berbeda.');

                return IngestionResult::conflict('payload_conflict', 'Isi batch berbeda dari batch yang sudah diterima.');
            }

            if ($sequence !== $run->last_batch_sequence + 1) {
                return IngestionResult::conflict('sequence_gap', 'Urutan batch tidak sesuai.', [
                    'expected_sequence' => $run->last_batch_sequence + 1,
                ]);
            }

            $itemCount = $this->store($run, $payload);

            IngestionBatch::query()->create([
                'tenant_id' => $run->tenant_id,
                'run_id' => $run->id,
                'attempt' => $run->attempt,
                'batch_sequence' => $sequence,
                'payload_sha256' => $sha,
                'item_count' => $itemCount,
                'status' => 'accepted',
                'received_at' => now(),
            ]);

            $run->update([
                'last_batch_sequence' => $sequence,
                'heartbeat_at' => now(),
                ...$this->progress($run, $payload['progress'] ?? []),
            ]);

            return IngestionResult::ok(['last_batch_sequence' => $sequence]);
        });
    }

    /** @param array<string, mixed> $payload */
    public function heartbeat(AnalysisRun $run, array $payload): IngestionResult
    {
        return DB::transaction(function () use ($run, $payload) {
            $run = AnalysisRun::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($run->id);

            if ($guard = $this->guard($run, (int) $payload['attempt'])) {
                return $guard;
            }

            $run->update(['heartbeat_at' => now(), ...$this->progress($run, $payload)]);

            return IngestionResult::ok(['cancel_requested' => $run->cancel_requested_at !== null]);
        });
    }

    /** @param array<string, mixed> $payload */
    public function complete(AnalysisRun $run, array $payload): IngestionResult
    {
        $result = DB::transaction(function () use ($run, $payload) {
            $run = AnalysisRun::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($run->id);

            if ($guard = $this->guard($run, (int) $payload['attempt'])) {
                return $guard;
            }

            if ((int) $payload['final_batch_sequence'] !== $run->last_batch_sequence) {
                return IngestionResult::conflict('sequence_gap', 'Masih ada batch yang belum diterima.', [
                    'expected_sequence' => $run->last_batch_sequence + 1,
                ]);
            }

            $status = match ($payload['status']) {
                'succeeded' => RunStatus::Succeeded,
                'cancelled' => RunStatus::Cancelled,
                default => RunStatus::Failed,
            };

            if ($status === RunStatus::Succeeded) {
                $pending = EventMedia::query()->withoutGlobalScopes()
                    ->whereIn('event_id', AnomalyEvent::query()->withoutGlobalScopes()->where('run_id', $run->id)->select('id'))
                    ->where('state', 'PENDING')
                    ->exists();

                if ($pending) {
                    return IngestionResult::conflict('evidence_pending', 'Bukti video masih diproses; run belum dapat diselesaikan.');
                }
            }

            AnomalyEvent::query()->withoutGlobalScopes()->where('run_id', $run->id)
                ->where('lifecycle', 'active')->update(['lifecycle' => 'closed']);

            $run->update([
                'status' => $status,
                'phase' => strtolower($status->value),
                'progress' => $status === RunStatus::Succeeded ? 100 : $run->progress,
                'error_code' => $payload['error_code'] ?? null,
                'error_message' => $payload['error_message'] ?? null,
                'result_manifest' => $payload['manifest'] ?? null,
                'finished_at' => now(),
            ]);

            if ($status === RunStatus::Failed && ($payload['error_code'] ?? null) === 'source_unreadable') {
                SystemEvents::forRun($run, 'source_unreadable', (string) $run->attempt, ['message' => $payload['error_message'] ?? null]);
            }

            $run->video?->update([
                'last_run_finished_at' => now(),
                'original_expires_at' => now()->addDays(config('kitchen.retention_days.original_video')),
            ]);

            return IngestionResult::ok(['status' => $status->value]);
        });

        if ($result->status === 200 && ($result->body['status'] ?? null) === RunStatus::Succeeded->value) {
            $this->notifyReviewers($run->fresh());
        }

        return $result;
    }

    private function guard(AnalysisRun $run, int $attempt): ?IngestionResult
    {
        if ($attempt !== $run->attempt) {
            return IngestionResult::conflict('stale_attempt', 'Attempt ini sudah tidak aktif.', ['active_attempt' => $run->attempt]);
        }

        if ($run->status !== RunStatus::Running) {
            return IngestionResult::conflict('run_not_active', 'Run tidak dalam status RUNNING.', ['status' => $run->status->value]);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $progress
     * @return array<string, mixed>
     */
    private function progress(AnalysisRun $run, array $progress): array
    {
        if (! isset($progress['processed_ms'])) {
            return [];
        }

        $processed = max($run->processed_ms, (int) $progress['processed_ms']);
        $duration = max(1, (int) data_get($run->input_snapshot, 'video.duration_ms', 1));

        return [
            'processed_ms' => $processed,
            'progress' => min(99, (int) floor($processed / $duration * 100)),
            'phase' => $progress['phase'] ?? $run->phase,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function store(AnalysisRun $run, array $payload): int
    {
        $base = ['tenant_id' => $run->tenant_id, 'run_id' => $run->id];
        $tracks = [];

        $trackId = function (string $key, int $first, int $last) use (&$tracks, $run, $base): string {
            if (! isset($tracks[$key])) {
                $track = PersonTrack::query()->withoutGlobalScopes()->firstOrCreate(
                    ['run_id' => $run->id, 'track_key' => $key],
                    $base + ['first_ms' => $first, 'last_ms' => $last],
                );
                $tracks[$key] = $track;
            }

            $track = $tracks[$key];
            if ($first < $track->first_ms || $last > $track->last_ms) {
                $track->update(['first_ms' => min($first, $track->first_ms), 'last_ms' => max($last, $track->last_ms)]);
            }

            return $track->id;
        };

        foreach ($payload['tracks'] ?? [] as $t) {
            $trackId((string) $t['track_key'], (int) $t['first_ms'], (int) $t['last_ms']);
        }

        foreach ($payload['activity_segments'] ?? [] as $s) {
            ActivitySegment::query()->create($base + [
                'track_id' => $trackId((string) $s['track_key'], (int) $s['start_ms'], (int) $s['end_ms']),
                'zone_id' => $s['zone_id'] ?? null,
                'label' => $s['label'],
                'start_ms' => $s['start_ms'],
                'end_ms' => $s['end_ms'],
                'score' => $s['score'] ?? null,
                'evidence' => $s['evidence'] ?? [],
            ]);
        }

        foreach ($payload['ppe_assessments'] ?? [] as $p) {
            PpeAssessment::query()->create($base + [
                'track_id' => $trackId((string) $p['track_key'], (int) $p['start_ms'], (int) $p['end_ms']),
                'item_code' => $p['item_code'],
                'required' => $p['required'],
                'state' => $p['state'],
                'start_ms' => $p['start_ms'],
                'end_ms' => $p['end_ms'],
                'score' => $p['score'] ?? null,
                'visibility' => $p['visibility'] ?? null,
                'reason' => $p['reason'] ?? null,
            ]);
        }

        foreach ($payload['events'] ?? [] as $e) {
            $this->upsertEvent($run, $e, isset($e['track_key']) ? $trackId((string) $e['track_key'], (int) $e['first_ms'], (int) $e['last_ms']) : null);
        }

        return count($payload['tracks'] ?? []) + count($payload['activity_segments'] ?? [])
            + count($payload['ppe_assessments'] ?? []) + count($payload['events'] ?? []);
    }

    /** @param array<string, mixed> $e */
    private function upsertEvent(AnalysisRun $run, array $e, ?string $trackId): void
    {
        $key = self::dedupeKey($run, $e['track_key'] ?? null, $e['type'], (int) $e['episode_start_ms']);
        $recordedAt = data_get($run->input_snapshot, 'video.recorded_at');

        $event = AnomalyEvent::query()->withoutGlobalScopes()
            ->where('tenant_id', $run->tenant_id)->where('dedupe_key', $key)->lockForUpdate()->first();

        if ($event) {
            $event->update([
                'first_ms' => min($event->first_ms, (int) $e['first_ms']),
                'last_ms' => max($event->last_ms, (int) $e['last_ms']),
                'lifecycle' => $e['lifecycle'],
                'peak_score' => max((float) $event->peak_score, (float) ($e['peak_score'] ?? 0)) ?: null,
                'evidence' => array_merge($event->evidence ?? [], $e['evidence'] ?? []),
            ]);
        } else {
            $event = AnomalyEvent::query()->create([
                'tenant_id' => $run->tenant_id,
                'run_id' => $run->id,
                'source_id' => $run->source_id,
                'track_id' => $trackId,
                'type' => $e['type'],
                'category' => EventCategory::from($e['category']),
                'severity' => $e['severity'],
                'lifecycle' => $e['lifecycle'],
                'first_ms' => $e['first_ms'],
                'last_ms' => $e['last_ms'],
                'occurred_at' => $recordedAt ? CarbonImmutable::parse($recordedAt)->addMilliseconds((int) $e['first_ms']) : null,
                'peak_score' => $e['peak_score'] ?? null,
                'evidence' => $e['evidence'] ?? [],
                'is_simulation' => $run->isSimulation(),
                'dedupe_key' => $key,
            ]);
        }

        foreach ($e['media'] ?? [] as $m) {
            EventMedia::query()->withoutGlobalScopes()->updateOrCreate(
                ['event_id' => $event->id, 'kind' => $m['kind']],
                [
                    'tenant_id' => $run->tenant_id,
                    'disk' => config('kitchen.storage_disk'),
                    'key' => $m['key'] ?? null,
                    'state' => $m['state'],
                    'start_ms' => $m['start_ms'] ?? null,
                    'end_ms' => $m['end_ms'] ?? null,
                    'checksum' => $m['checksum'] ?? null,
                    'error_code' => $m['error_code'] ?? null,
                    'expires_at' => now()->addDays(config('kitchen.retention_days.evidence')),
                ],
            );
        }
    }

    private function failRun(AnalysisRun $run, string $code, string $message): void
    {
        $run->update([
            'status' => RunStatus::Failed,
            'phase' => 'failed',
            'error_code' => $code,
            'error_message' => $message,
            'finished_at' => now(),
        ]);
    }

    private function notifyReviewers(AnalysisRun $run): void
    {
        $open = AnomalyEvent::query()->withoutGlobalScopes()
            ->where('run_id', $run->id)->where('review_status', 'OPEN')->count();

        if ($open === 0) {
            return;
        }

        $reviewers = TenantMembership::query()->where('tenant_id', $run->tenant_id)->where('status', 'active')
            ->with('user')->get()
            ->filter(fn ($m) => $m->role->can(Permission::ReviewEvents))
            ->pluck('user');

        Notification::send($reviewers, new EventsNeedReview($run, $open));
    }
}
