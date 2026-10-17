<?php

namespace App\Domain\Analysis;

use App\Enums\RunStatus;
use App\Models\AnalysisRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Hands a queued run to the Python worker. Only one run may be RUNNING per
 * machine; others stay QUEUED (visible and cancellable) until the slot frees.
 */
class DispatchRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $timeout = 60;

    public function __construct(public string $runId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addDay();
    }

    public function handle(WorkerClient $worker): void
    {
        $token = Str::random(48);

        $claimed = DB::transaction(function () use ($token) {
            DB::select('SELECT pg_advisory_xact_lock(?)', [crc32('anpixel-analysis-slot')]);

            $run = AnalysisRun::query()->withoutGlobalScopes()->lockForUpdate()->find($this->runId);

            if (! $run || $run->status !== RunStatus::Queued) {
                return null;
            }

            $busy = AnalysisRun::query()->withoutGlobalScopes()
                ->where('status', RunStatus::Running->value)
                ->exists();

            if ($busy) {
                return false;
            }

            $run->update([
                'status' => RunStatus::Running,
                'phase' => 'dispatching',
                'worker_token_hash' => hash('sha256', $token),
                'started_at' => $run->started_at ?? now(),
                'heartbeat_at' => now(),
            ]);

            return $run;
        });

        if ($claimed === null) {
            return;
        }

        if ($claimed === false) {
            $this->release(15);

            return;
        }

        try {
            $worker->submit([
                'run_id' => $claimed->id,
                'tenant_id' => $claimed->tenant_id,
                'attempt' => $claimed->attempt,
                'worker_token' => $token,
                'callback_base' => rtrim(config('kitchen.worker.callback_url'), '/')."/internal/runs/{$claimed->id}",
                'storage_root' => AnalysisService::storageRoot(),
                'input' => $claimed->input_snapshot,
            ]);
        } catch (Throwable $e) {
            Log::warning('analysis.dispatch_failed', ['run_id' => $claimed->id, 'tenant_id' => $claimed->tenant_id, 'error' => $e->getMessage()]);
            $claimed->update(['status' => RunStatus::Queued, 'phase' => 'waiting_worker', 'worker_token_hash' => null]);

            throw $e;
        }
    }

    public function failed(?Throwable $e): void
    {
        AnalysisRun::query()->withoutGlobalScopes()->whereKey($this->runId)
            ->whereIn('status', [RunStatus::Queued->value, RunStatus::Running->value])
            ->update([
                'status' => RunStatus::Failed->value,
                'phase' => 'failed',
                'error_code' => 'worker_unreachable',
                'error_message' => 'AI worker could not be reached after several attempts.',
                'finished_at' => now(),
            ]);
    }
}
