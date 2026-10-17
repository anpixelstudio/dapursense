<?php

namespace App\Console\Commands;

use App\Domain\Reports\ReportScheduler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('reports:run-schedules')]
#[Description('Generate due daily/weekly scheduled reports')]
class RunReportSchedules extends Command
{
    public function handle(ReportScheduler $scheduler): int
    {
        $this->info('Scheduled reports generated: '.$scheduler->runDue());

        return self::SUCCESS;
    }
}
