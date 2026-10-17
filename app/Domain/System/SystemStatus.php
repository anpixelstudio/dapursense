<?php

namespace App\Domain\System;

use App\Domain\Analysis\WorkerClient;
use App\Models\AiModel;
use App\Models\SystemInstallation;
use App\Models\SystemRelease;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SystemStatus
{
    public function __construct(
        private ReleaseManifest $manifest,
        private StorageMonitor $storage,
        private WorkerClient $worker,
    ) {}

    /** @return array<string, mixed> */
    public function collect(bool $checkWorker = true): array
    {
        $database = $this->check(fn () => DB::select('select 1'));
        $installed = $database && Schema::hasTable('system_releases');
        $release = $installed ? SystemRelease::query()->latest('installed_at')->first() : null;
        $worker = $checkWorker ? $this->worker->health() : null;
        $paused = $installed ? (bool) SystemSetting::get('analysis.paused', false) : false;
        $realModel = $installed && AiModel::query()->whereIn('validation_status', ['VALIDATED', 'ACTIVE'])->where('runtime', '!=', 'simulation')->exists();

        return [
            'app_version' => $this->manifest->version(),
            'tagged_release' => $this->manifest->isTagged(),
            'git_sha' => $this->manifest->gitSha(),
            'installed' => $release !== null,
            'schema' => [
                'installed_version' => $release?->app_version,
                'installed_schema_hash' => $release?->schema_hash,
                'current_schema_hash' => $this->manifest->schemaHash(),
                'pending_migrations' => $database ? $this->pendingMigrations() : [],
                'modified_migrations' => $release ? $this->modifiedMigrations($release) : [],
            ],
            'services' => [
                'database' => $database,
                'redis' => $this->check(fn () => Redis::connection()->ping()),
                'storage_used_percent' => $this->storage->usedPercent(),
                'storage_warning' => $this->storage->isWarning(),
                'storage_blocked' => $this->storage->isBlocked(),
                'worker' => $worker,
            ],
            'analysis_paused' => $paused,
            'analysis_ready' => $worker !== null && ($worker['ok'] ?? false) && ! $paused,
            'real_detection_model' => $realModel,
            'last_installation' => $installed ? SystemInstallation::query()->latest('started_at')->first()?->only([
                'id', 'operation', 'target_version', 'status', 'last_step', 'error_code', 'started_at', 'finished_at',
            ]) : null,
        ];
    }

    /** @return list<string> */
    public function pendingMigrations(): array
    {
        if (! Schema::hasTable('migrations')) {
            return array_keys($this->manifest->migrations());
        }

        $ran = DB::table('migrations')->pluck('migration')->all();

        return array_values(array_diff(array_keys($this->manifest->migrations()), $ran));
    }

    /**
     * Migrations recorded in the installed release whose file content changed or disappeared.
     *
     * @return list<string>
     */
    public function modifiedMigrations(SystemRelease $release): array
    {
        $current = $this->manifest->migrations();
        $modified = [];

        foreach ($release->migration_manifest as $name => $checksum) {
            if (! isset($current[$name]) || ! hash_equals($checksum, $current[$name])) {
                $modified[] = $name;
            }
        }

        return $modified;
    }

    private function check(callable $probe): bool
    {
        try {
            $probe();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
