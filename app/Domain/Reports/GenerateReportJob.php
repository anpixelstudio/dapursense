<?php

namespace App\Domain\Reports;

use App\Domain\System\ReleaseManifest;
use App\Models\Report;
use App\Models\User;
use App\Notifications\ReportReady;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(public string $reportId) {}

    public function handle(ReportBuilder $builder, ReleaseManifest $release): void
    {
        $report = Report::query()->withoutGlobalScopes()->find($this->reportId);

        if (! $report || ! in_array($report->status, ['QUEUED', 'RUNNING'], true)) {
            return;
        }

        $report->update(['status' => 'RUNNING']);

        try {
            $data = $builder->build($report);
            $key = "tenants/{$report->tenant_id}/reports/{$report->id}.{$report->format}";
            $disk = Storage::disk(config('kitchen.storage_disk'));

            $contents = $report->format === 'csv' ? $this->csv($data) : $this->pdf($data, $release->version());
            $disk->put($key, $contents);

            $report->update([
                'status' => 'READY',
                'disk' => config('kitchen.storage_disk'),
                'key' => $key,
                'checksum' => hash('sha256', $contents),
                'completed_at' => now(),
                'expires_at' => now()->addDays(config('kitchen.retention_days.reports')),
                'manifest' => [
                    'app_version' => $release->version(),
                    'run_ids' => array_column($data['runs'], 'id'),
                    'event_count' => count($data['events']),
                    'contains_simulation' => $data['identity']['contains_simulation'],
                    'aggregation' => ['activity_unit' => 'person-seconds', 'ppe_ratio' => 'present/(present+missing)', 'coverage' => 'assessable/relevant'],
                ],
            ]);
        } catch (Throwable $e) {
            Log::error('report.failed', ['report_id' => $report->id, 'tenant_id' => $report->tenant_id, 'error' => $e->getMessage()]);
            $report->update(['status' => 'FAILED', 'error_code' => 'generation_failed']);
        }

        if ($report->requested_by && ($user = User::query()->find($report->requested_by))) {
            $user->notify(new ReportReady($report->fresh()));
        }
    }

    /** @param array<string, mixed> $data */
    private function csv(array $data): string
    {
        $handle = fopen('php://temp', 'r+');
        $filters = json_encode($data['filters'], JSON_UNESCAPED_UNICODE);
        $columns = ['event_id', 'run_id', 'simulation', 'source', 'type', 'category', 'severity', 'review_status', 'track',
            'relative_start', 'relative_end', 'occurred_at', 'duration_s', 'reviewer', 'review_reason', 'action_note',
            'reviewed_at', 'snapshot', 'clip', 'event_url'];

        fputcsv($handle, [...$columns, 'report_id', 'timezone', 'cutoff_at', 'filters'], escape: '');

        foreach ($data['events'] as $row) {
            $values = array_map(fn ($c) => is_bool($row[$c]) ? ($row[$c] ? 'yes' : 'no') : $row[$c], $columns);
            fputcsv($handle, [...$values, $data['identity']['report_id'], $data['identity']['timezone'], $data['identity']['cutoff_at'], $filters], escape: '');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return "\u{FEFF}".$csv;
    }

    /** @param array<string, mixed> $data */
    private function pdf(array $data, string $version): string
    {
        return Pdf::loadView('reports.pdf', ['data' => $data, 'version' => $version])
            ->setPaper('a4', 'portrait')
            ->output();
    }
}
