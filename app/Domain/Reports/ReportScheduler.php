<?php

namespace App\Domain\Reports;

use App\Models\Report;
use App\Models\ReportSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ReportScheduler
{
    /** Next run is 01:00 tenant time after the period closes. */
    public function nextRunAt(string $frequency, string $tz, ?CarbonImmutable $after = null): CarbonImmutable
    {
        $local = ($after ?? CarbonImmutable::now())->setTimezone($tz);

        $next = $frequency === 'weekly'
            ? $local->startOfWeek()->addWeek()->setTime(1, 0)
            : $local->startOfDay()->addDay()->setTime(1, 0);

        return $next->utc();
    }

    /**
     * Idempotent per period: the period start is recorded on the schedule, so a
     * repeated scheduler tick for the same period does not create a second report.
     */
    public function runDue(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $created = 0;

        $due = ReportSchedule::query()->withoutGlobalScopes()
            ->where('enabled', true)->where('next_run_at', '<=', $now)->pluck('id');

        foreach ($due as $id) {
            $reportId = DB::transaction(function () use ($id, $now) {
                $schedule = ReportSchedule::query()->withoutGlobalScopes()->lockForUpdate()->find($id);

                if (! $schedule || $schedule->next_run_at > $now) {
                    return null;
                }

                $runLocal = $schedule->next_run_at->setTimezone($schedule->timezone);
                $periodEnd = $runLocal->startOfDay()->subDay();
                $periodStart = $schedule->frequency === 'weekly' ? $periodEnd->subDays(6) : $periodEnd;

                $schedule->update(['next_run_at' => $this->nextRunAt($schedule->frequency, $schedule->timezone, $now)]);

                if ($schedule->last_period_key_at?->equalTo($periodStart->utc())) {
                    return null;
                }

                $schedule->update(['last_period_key_at' => $periodStart->utc()]);

                return Report::query()->withoutGlobalScopes()->create([
                    'tenant_id' => $schedule->tenant_id,
                    'requested_by' => $schedule->created_by,
                    'format' => $schedule->format,
                    'status' => 'QUEUED',
                    'filters' => [
                        ...$schedule->filters,
                        'from_date' => $periodStart->toDateString(),
                        'to_date' => $periodEnd->toDateString(),
                        'schedule_id' => $schedule->id,
                    ],
                    'cutoff_at' => $now,
                ])->id;
            });

            if ($reportId) {
                GenerateReportJob::dispatch($reportId);
                $created++;
            }
        }

        return $created;
    }
}
