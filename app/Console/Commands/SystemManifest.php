<?php

namespace App\Console\Commands;

use App\Domain\System\ReleaseManifest;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('system:manifest {--app-version= : SemVer from release tool} {--build-id= : CI build ID} {--git-sha= : Commit SHA}')]
#[Description('Write immutable release-manifest.json for release artifacts (CI pipeline)')]
class SystemManifest extends Command
{
    public function handle(ReleaseManifest $manifest): int
    {
        $version = (string) $this->option('app-version');

        if (! preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?$/', $version)) {
            $this->components->error('--app-version must be SemVer, e.g. 1.2.3');

            return self::FAILURE;
        }

        $data = [
            'app_version' => $version,
            'git_sha' => $this->option('git-sha') ?: $manifest->gitSha(),
            'build_id' => $this->option('build-id'),
            'built_at' => now()->toIso8601String(),
            'api_contract_version' => config('kitchen.worker.contract_version'),
            'migrations' => $manifest->migrations(),
            'schema_hash' => $manifest->schemaHash(),
            'dependency_locks' => $manifest->dependencyLocks(),
        ];

        File::put($manifest->path(), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $this->components->info("release-manifest.json written for {$version}");

        return self::SUCCESS;
    }
}
