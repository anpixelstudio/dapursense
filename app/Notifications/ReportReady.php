<?php

namespace App\Notifications;

use App\Models\Report;
use Illuminate\Notifications\Notification;

class ReportReady extends Notification
{
    public function __construct(private Report $report) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $ready = $this->report->status === 'READY';

        return [
            'tenant_id' => $this->report->tenant_id,
            'kind' => 'report_ready',
            'title' => $ready ? 'Laporan '.strtoupper($this->report->format).' siap diunduh' : 'Laporan gagal dibuat',
            'body' => 'Report ID '.$this->report->id,
            'url' => route('reports.index', [], false),
        ];
    }
}
