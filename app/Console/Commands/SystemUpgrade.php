<?php

namespace App\Console\Commands;

use App\Domain\System\OperationFailed;
use App\Domain\System\ReleaseManifest;
use App\Domain\System\SystemStatus;
use App\Enums\RunStatus;
use App\Models\AnalysisRun;
use App\Models\SystemRelease;
use App\Models\SystemSetting;
use Database\Seeders\SystemSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

#[Signature('system:upgrade
    {--non-interactive : Non-interactive mode}
    {--backup-verified : Confirm backup verified (required in production)}
    {--drain-timeout=120 : Seconds to wait for active runs to finish}')]
#[Description('Upgrade to this release: migration checksum, pause analysis, migrate, seed, health check')]
class SystemUpgrade extends SystemOperation
{
    protected function operation(): string
    {
        return 'upgrade';
    }

    protected function perform(): void
    {
        $manifest = app(ReleaseManifest::class);
        $installed = Schema::hasTable('system_releases') ? SystemRelease::query()->latest('installed_at')->first() : null;

        if (! $installed) {
            throw new OperationFailed('not_installed', 'Application is not installed; run php artisan system:install.');
        }

        $this->report['from_version'] = $installed->app_version;
        $this->startRecord();

        $this->step('checksum', function () use ($installed) {
            $modified = app(SystemStatus::class)->modifiedMigrations($installed);

            if ($modified !== []) {
                throw new OperationFailed('migration_modified', 'Installed migration(s) were modified; create a new migration: '.implode(', ', $modified));
            }
        });

        if (app()->isProduction() && ! $this->option('backup-verified')) {
            throw new OperationFailed('backup_required', 'Production requires --backup-verified after the backup has been tested.', self::EXIT_PREFLIGHT);
        }

        $before = app(SystemStatus::class)->pendingMigrations();
        $this->step('pause', fn () => $this->pauseAnalysis());

        $this->step('migrate', fn () => $this->migrate());
        $this->report['migrations_applied'] = $before;

        $this->step('seed', fn () => (new SystemSeeder)->run());
        $this->step('health', fn () => $this->report['analysis_ready'] = app(SystemStatus::class)->collect()['analysis_ready']);
        $this->step('release', fn () => $this->recordRelease());

        SystemSetting::put('analysis.paused', false);
        $this->report['to_version'] = $manifest->version();
        Artisan::call('optimize:clear');
    }

    private function pauseAnalysis(): void
    {
        SystemSetting::put('analysis.paused', true);
        Artisan::call('queue:restart');

        $deadline = time() + (int) $this->option('drain-timeout');

        while (AnalysisRun::query()->withoutGlobalScopes()->where('status', RunStatus::Running->value)->exists()) {
            if (time() >= $deadline) {
                SystemSetting::put('analysis.paused', false);

                throw new OperationFailed('workers_busy', 'Analysis is still running; wait for completion or cancel runs, then retry the upgrade.');
            }

            sleep(2);
        }
    }
}
