<?php

namespace App\Console\Commands;

use App\Domain\Reports\ReportScheduler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('reports:run-schedules')]
#[Description('Buat laporan terjadwal harian/mingguan yang sudah jatuh tempo')]
class RunReportSchedules extends Command
{
    public function handle(ReportScheduler $scheduler): int
    {
        $this->info('Laporan terjadwal dibuat: '.$scheduler->runDue());

        return self::SUCCESS;
    }
}
