<?php

namespace App\Console\Commands;

use App\Domain\System\InstallLock;
use App\Domain\System\OperationFailed;
use App\Domain\System\ReleaseManifest;
use App\Domain\System\SystemStatus;
use App\Models\SystemInstallation;
use App\Models\SystemRelease;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Shared orchestration for system:install and system:upgrade: preflight, lock,
 * checkpointed steps and a redacted JSON report.
 *
 * Exit codes: 0 success, 1 operation failed, 2 preflight failed, 3 lock in use, 4 upgrade required.
 */
abstract class SystemOperation extends Command
{
    public const EXIT_FAILED = 1;

    public const EXIT_PREFLIGHT = 2;

    public const EXIT_LOCKED = 3;

    public const EXIT_UPGRADE_REQUIRED = 4;

    protected ?SystemInstallation $installation = null;

    /** @var array<string, mixed> */
    protected array $report = [];

    abstract protected function operation(): string;

    /** Runs after the lock is acquired. */
    abstract protected function perform(): void;

    public function handle(ReleaseManifest $manifest, InstallLock $lock): int
    {
        $this->report = [
            'id' => (string) Str::uuid(),
            'operation' => $this->operation(),
            'target_version' => $manifest->version(),
            'started_at' => now()->toIso8601String(),
            'steps' => [],
            'mode' => $this->hasOption('demo') && $this->option('demo') ? 'demo' : 'actual',
        ];

        try {
            $this->step('preflight', fn () => $this->preflight());

            if (! $lock->acquire()) {
                throw new OperationFailed('lock_in_use', 'Instalasi atau upgrade lain sedang berjalan.', self::EXIT_LOCKED);
            }

            $this->perform();
            $this->finish('SUCCEEDED');
            $this->newLine();
            $this->components->info(ucfirst($this->operation())." selesai: versi {$this->report['target_version']}.");

            return self::SUCCESS;
        } catch (OperationFailed $e) {
            $this->report['error'] = ['code' => $e->errorCode, 'message' => $e->getMessage()];
            $this->finish('FAILED', $e->errorCode);
            $this->components->error($e->getMessage());

            return $e->exitCode;
        } catch (Throwable $e) {
            $this->report['error'] = ['code' => 'unexpected', 'message' => $e->getMessage()];
            $this->finish('FAILED', 'unexpected');
            $this->components->error('Operasi gagal: '.$e->getMessage());

            return self::EXIT_FAILED;
        } finally {
            $lock->release();
        }
    }

    protected function step(string $name, callable $callback): mixed
    {
        if (config('kitchen.install.fail_at_step') === $name) {
            throw new OperationFailed('simulated_failure', "Kegagalan simulasi pada langkah {$name}.");
        }

        $started = microtime(true);
        $this->components->task($name, function () use ($callback, &$result) {
            $result = $callback();

            return true;
        });

        $this->report['steps'][] = ['name' => $name, 'duration_ms' => (int) ((microtime(true) - $started) * 1000)];
        $this->installation?->update(['last_step' => $name]);

        return $result;
    }

    protected function startRecord(): void
    {
        $this->installation = SystemInstallation::query()->create([
            'target_version' => $this->report['target_version'],
            'operation' => $this->operation(),
            'status' => 'RUNNING',
            'last_step' => 'started',
            'manifest' => [],
            'started_at' => now(),
        ]);
        $this->report['id'] = $this->installation->id;
    }

    protected function preflight(): void
    {
        $checks = [
            'php' => version_compare(PHP_VERSION, '8.3.0', '>='),
            'extensions' => collect(['pdo_pgsql', 'mbstring', 'openssl', 'fileinfo', 'redis'])->every(fn ($e) => extension_loaded($e)),
            'app_key' => $this->ensureAppKey(),
            'database' => $this->probe(fn () => DB::select('select 1')) && DB::getDriverName() === 'pgsql',
            'redis' => $this->probe(fn () => Redis::connection()->ping()),
            'storage' => $this->probe(function () {
                $disk = Storage::disk(config('kitchen.storage_disk'));
                $disk->put('.install-probe', 'ok');
                $ok = $disk->get('.install-probe') === 'ok';
                $disk->delete('.install-probe');

                return $ok ?: throw new \RuntimeException('storage');
            }),
            'ffmpeg' => $this->binary(config('kitchen.binaries.ffmpeg')),
            'ffprobe' => $this->binary(config('kitchen.binaries.ffprobe')),
            'release_manifest' => app()->isProduction() ? app(ReleaseManifest::class)->isTagged() : true,
        ];

        $this->report['preflight'] = $checks;
        $failed = array_keys(array_filter($checks, fn ($ok) => ! $ok));

        if ($failed) {
            throw new OperationFailed('preflight_failed', 'Preflight gagal: '.implode(', ', $failed), self::EXIT_PREFLIGHT);
        }
    }

    protected function migrate(): void
    {
        $exit = Artisan::call('migrate', ['--force' => true]);
        $this->report['migrate_output'] = trim(Artisan::output());

        // The migrate exit code alone is not trusted; the manifest is verified afterwards.
        $pending = app(SystemStatus::class)->pendingMigrations();

        if ($exit !== 0 || $pending !== []) {
            throw new OperationFailed('migration_incomplete', 'Migration belum lengkap: '.implode(', ', $pending ?: ['exit '.$exit]));
        }
    }

    protected function recordRelease(): void
    {
        $manifest = app(ReleaseManifest::class);

        SystemRelease::query()->updateOrCreate(
            ['app_version' => $manifest->version()],
            [
                'git_sha' => $manifest->gitSha(),
                'build_id' => $manifest->built()['build_id'] ?? null,
                'schema_hash' => $manifest->schemaHash(),
                'migration_manifest' => $manifest->migrations(),
                'installed_at' => now(),
            ],
        );
    }

    protected function finish(string $status, ?string $errorCode = null): void
    {
        $this->report['status'] = $status;
        $this->report['finished_at'] = now()->toIso8601String();
        $report = Audit::redact($this->report);
        $key = "install-reports/{$this->report['id']}.json";

        try {
            Storage::disk(config('kitchen.storage_disk'))->put($key, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (Throwable) {
            $key = null;
        }

        try {
            if ($this->installation === null && $status === 'FAILED' && Schema::hasTable('system_installations')) {
                $this->startRecord();
            }

            $this->installation?->update([
                'status' => $status,
                'error_code' => $errorCode,
                'report_key' => $key,
                'manifest' => array_intersect_key($report, array_flip(['mode', 'analysis_ready', 'migrations_applied', 'preflight'])),
                'finished_at' => now(),
            ]);
        } catch (Throwable) {
            // The private report file remains the record when the database is unavailable.
        }

        $this->line("Laporan: storage/app/private/{$key}");
    }

    private function ensureAppKey(): bool
    {
        if (filled(config('app.key'))) {
            return true;
        }

        $env = base_path('.env');

        if (! is_writable($env)) {
            return false;
        }

        return Artisan::call('key:generate', ['--force' => true]) === 0;
    }

    private function binary(string $binary): bool
    {
        $process = new Process([$binary, '-version']);
        $process->setTimeout(10);
        $process->run();

        return $process->isSuccessful();
    }

    private function probe(callable $callback): bool
    {
        try {
            $callback();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
