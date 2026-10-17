<?php

use Database\Seeders\SystemSeeder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/*
 * Black-box tests: system:install / system:upgrade run as real subprocesses
 * against a dedicated, initially empty PostgreSQL database.
 */

const INSTALL_DB = 'kitchen_intelligence_install_test';

beforeEach(function () {
    (new Process(['dropdb', '--if-exists', '--force', INSTALL_DB]))->mustRun();
    (new Process(['createdb', INSTALL_DB]))->mustRun();

    config(['database.connections.install' => array_merge(config('database.connections.pgsql'), ['database' => INSTALL_DB])]);
    DB::purge('install');

    $this->passwordFile = tempnam(sys_get_temp_dir(), 'pw');
    file_put_contents($this->passwordFile, 'RahasiaSuper123');
    chmod($this->passwordFile, 0600);
});

afterEach(function () {
    DB::disconnect('install');
    @unlink($this->passwordFile);
});

function runArtisan(array $args, array $env = []): Process
{
    $process = new Process(['php', 'artisan', ...$args], base_path(), $env + [
        'DB_DATABASE' => INSTALL_DB,
        'AI_SERVICE_URL' => 'http://127.0.0.1:9',
        'KITCHEN_INSTALL_FAIL_AT_STEP' => '',
    ]);
    $process->setTimeout(180);
    $process->run();

    return $process;
}

function runInstall(object $test, array $extra = [], array $env = []): Process
{
    return runArtisan(['system:install', '--non-interactive', '--admin-email=owner@hotel.test', '--admin-password-file='.$test->passwordFile, ...$extra], $env);
}

function installDb()
{
    return DB::connection('install');
}

test('install on an empty database creates schema, seed, owner and a release record', function () {
    $process = runInstall($this);

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());

    $db = installDb();
    expect($db->table('system_releases')->count())->toBe(1)
        ->and($db->table('ai_models')->count())->toBe(count(SystemSeeder::models()))
        ->and($db->table('users')->where('email', 'owner@hotel.test')->value('is_platform_admin'))->toBeTrue()
        ->and($db->table('tenant_memberships')->where('role', 'owner')->count())->toBe(1)
        ->and($db->table('system_installations')->where('status', 'SUCCEEDED')->count())->toBe(1);
});

test('re-running install is idempotent and never resets the owner password', function () {
    runInstall($this);
    $hash = installDb()->table('users')->where('email', 'owner@hotel.test')->value('password');

    file_put_contents($this->passwordFile, 'PasswordLainSekali');
    $process = runInstall($this);

    expect($process->getExitCode())->toBe(0)
        ->and(installDb()->table('users')->count())->toBe(1)
        ->and(installDb()->table('tenants')->count())->toBe(1)
        ->and(installDb()->table('ai_models')->count())->toBe(count(SystemSeeder::models()))
        ->and(installDb()->table('users')->where('email', 'owner@hotel.test')->value('password'))->toBe($hash);
});

test('a concurrent install exits with code 3 while the lock is held', function () {
    installDb()->select('SELECT pg_advisory_lock(?)', [config('kitchen.install.lock_key')]);

    $process = runInstall($this);

    installDb()->select('SELECT pg_advisory_unlock(?)', [config('kitchen.install.lock_key')]);
    expect($process->getExitCode())->toBe(3);
});

test('a failure mid-install is reported and a re-run completes it', function () {
    $failed = runInstall($this, env: ['KITCHEN_INSTALL_FAIL_AT_STEP' => 'owner']);

    expect($failed->getExitCode())->toBe(1)
        ->and(installDb()->table('system_installations')->where('status', 'FAILED')->value('error_code'))->toBe('simulated_failure')
        ->and(installDb()->table('system_releases')->count())->toBe(0);

    $retry = runInstall($this);

    expect($retry->getExitCode())->toBe(0)
        ->and(installDb()->table('system_releases')->count())->toBe(1)
        ->and(installDb()->table('users')->count())->toBe(1);
});

test('install refuses a database from another version with exit code 4', function () {
    runInstall($this);
    installDb()->table('system_releases')->update(['app_version' => '0.0.1']);

    expect(runInstall($this)->getExitCode())->toBe(4);
});

test('non-interactive install without owner input fails preflight with exit code 2', function () {
    expect(runArtisan(['system:install', '--non-interactive'])->getExitCode())->toBe(2);
});

test('a world readable password file is refused', function () {
    chmod($this->passwordFile, 0644);

    expect(runInstall($this)->getExitCode())->toBe(2)
        ->and(installDb()->table('users')->count())->toBe(0);
});

test('the install report never contains the owner password', function () {
    runInstall($this);

    $key = installDb()->table('system_installations')->where('status', 'SUCCEEDED')->value('report_key');
    $report = file_get_contents(storage_path('app/private/'.$key));

    expect($report)->not->toContain('RahasiaSuper123')
        ->and(json_decode($report, true))->toHaveKeys(['operation', 'target_version', 'steps', 'preflight', 'status']);

    @unlink(storage_path('app/private/'.$key));
});

test('upgrade requires an installed system', function () {
    $process = runArtisan(['system:upgrade', '--non-interactive']);

    expect($process->getExitCode())->toBe(1)->and($process->getOutput())->toContain('system:install');
});

test('upgrade from an older version records the new release', function () {
    runInstall($this);
    installDb()->table('system_releases')->update(['app_version' => '0.0.1']);

    $process = runArtisan(['system:upgrade', '--non-interactive']);

    expect($process->getExitCode())->toBe(0, $process->getOutput())
        ->and(installDb()->table('system_releases')->where('app_version', '!=', '0.0.1')->count())->toBe(1)
        ->and(installDb()->table('system_settings')->where('key', 'analysis.paused')->value('value'))->toBe('false');
});

test('upgrade refuses edited migrations', function () {
    runInstall($this);
    $release = installDb()->table('system_releases')->first();
    $manifest = json_decode($release->migration_manifest, true);
    $first = array_key_first($manifest);
    $manifest[$first] = str_repeat('0', 64);
    installDb()->table('system_releases')->update(['migration_manifest' => json_encode($manifest)]);

    $process = runArtisan(['system:upgrade', '--non-interactive']);

    expect($process->getExitCode())->toBe(1)->and($process->getOutput())->toContain($first);
});

test('production preflight requires a tagged release manifest', function () {
    expect(runInstall($this, env: ['APP_ENV' => 'production'])->getExitCode())->toBe(2);
});

test('system:status reports install state as JSON', function () {
    runInstall($this);

    $status = json_decode(runArtisan(['system:status', '--json'])->getOutput(), true);

    expect($status['installed'])->toBeTrue()
        ->and($status['schema']['pending_migrations'])->toBe([])
        ->and($status['services']['database'])->toBeTrue()
        ->and($status['analysis_ready'])->toBeFalse();
});
