<?php

namespace App\Console\Commands;

use App\Domain\Analysis\DispatchRunJob;
use App\Domain\Events\SystemEvents;
use App\Enums\RunStatus;
use App\Models\ActivitySegment;
use App\Models\AnalysisRun;
use App\Models\PpeAssessment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('analysis:watchdog')]
#[Description('Mark runs with stalled heartbeats, limited retries, and create system anomalies')]
class AnalysisWatchdog extends Command
{
    public function handle(): int
    {
        $threshold = now()->subSeconds(config('kitchen.worker.heartbeat_stall_seconds'));

        $stalled = AnalysisRun::query()->withoutGlobalScopes()
            ->where('status', RunStatus::Running->value)
            ->where('heartbeat_at', '<', $threshold)
            ->pluck('id');

        foreach ($stalled as $id) {
            $this->handleStalled($id, $threshold);
        }

        $this->info("Stalled runs processed: {$stalled->count()}");

        return self::SUCCESS;
    }

    private function handleStalled(string $id, \DateTimeInterface $threshold): void
    {
        $retry = DB::transaction(function () use ($id, $threshold) {
            $run = AnalysisRun::query()->withoutGlobalScopes()->lockForUpdate()->find($id);

            if (! $run || $run->status !== RunStatus::Running || $run->heartbeat_at >= $threshold) {
                return false;
            }

            if ($run->cancel_requested_at) {
                $run->update(['status' => RunStatus::Cancelled, 'phase' => 'cancelled', 'finished_at' => now(), 'worker_token_hash' => null]);

                return false;
            }

            SystemEvents::forRun($run, 'analysis_stalled', (string) $run->attempt, [
                'attempt' => $run->attempt,
                'last_heartbeat_at' => $run->heartbeat_at?->toIso8601String(),
                'processed_ms' => $run->processed_ms,
            ]);

            if ($run->attempt >= config('kitchen.worker.max_attempts')) {
                $run->update([
                    'status' => RunStatus::Failed,
                    'phase' => 'failed',
                    'error_code' => 'analysis_stalled',
                    'error_message' => 'Worker stopped sending heartbeats and the retry limit was reached.',
                    'finished_at' => now(),
                    'worker_token_hash' => null,
                ]);

                return false;
            }

            // Derived intervals are recomputed by the new attempt; events are kept and deduplicated by dedupe_key.
            ActivitySegment::query()->withoutGlobalScopes()->where('run_id', $run->id)->delete();
            PpeAssessment::query()->withoutGlobalScopes()->where('run_id', $run->id)->delete();

            $run->update([
                'status' => RunStatus::Queued,
                'phase' => 'retrying',
                'attempt' => $run->attempt + 1,
                'last_batch_sequence' => 0,
                'processed_ms' => 0,
                'progress' => 0,
                'worker_token_hash' => null,
            ]);

            return true;
        });

        if ($retry) {
            DispatchRunJob::dispatch($id);
        }
    }
}
