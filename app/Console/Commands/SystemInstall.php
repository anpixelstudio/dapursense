<?php

namespace App\Console\Commands;

use App\Domain\Organization\TenantProvisioner;
use App\Domain\System\OperationFailed;
use App\Domain\System\ReleaseManifest;
use App\Domain\System\SystemStatus;
use App\Models\Kitchen;
use App\Models\MediaSource;
use App\Models\Property;
use App\Models\SystemRelease;
use App\Models\TenantMembership;
use App\Models\User;
use Database\Seeders\SystemSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[Signature('system:install
    {--demo : Create demo location and rule template}
    {--non-interactive : Non-interactive; all input from options}
    {--admin-email= : Initial owner email}
    {--admin-name=Owner : Initial owner name}
    {--organization=Primary Organization : Initial owner organization name}
    {--admin-password-file= : File containing owner password (mode 0600 or 0400)}')]
#[Description('Install application: preflight, migration, idempotent seed, initial owner, verify and install report')]
class SystemInstall extends SystemOperation
{
    protected function operation(): string
    {
        return 'install';
    }

    protected function perform(): void
    {
        $target = app(ReleaseManifest::class)->version();

        if (Schema::hasTable('system_releases')) {
            $installed = SystemRelease::query()->latest('installed_at')->first();

            if ($installed && $installed->app_version !== $target) {
                throw new OperationFailed('upgrade_required', "Database contains version {$installed->app_version}; run php artisan system:upgrade for {$target}.", self::EXIT_UPGRADE_REQUIRED);
            }
        }

        $this->step('migrate', fn () => $this->migrate());
        $this->report['migrations_applied'] = array_keys(app(ReleaseManifest::class)->migrations());
        $this->startRecord();

        $this->step('seed', fn () => (new SystemSeeder)->run());
        $this->step('owner', fn () => $this->ensureOwner());

        if ($this->option('demo')) {
            $this->step('demo', fn () => $this->seedDemo());
        }

        $this->step('directories', function () {
            foreach (['tenants', 'install-reports'] as $dir) {
                Storage::disk(config('kitchen.storage_disk'))->makeDirectory($dir);
            }
        });

        $this->step('verify', fn () => $this->verify());
        $this->step('release', fn () => $this->recordRelease());
    }

    private function ensureOwner(): void
    {
        $email = $this->option('admin-email');

        if (! $email && User::query()->where('is_platform_admin', true)->exists()) {
            $this->report['owner'] = 'existing';

            return;
        }

        if (! $email) {
            if ($this->option('non-interactive')) {
                throw new OperationFailed('owner_required', 'Non-interactive mode requires --admin-email on first install.', self::EXIT_PREFLIGHT);
            }
            $email = $this->ask('Email owner');
        }

        $email = strtolower(trim((string) $email));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new OperationFailed('owner_invalid', 'Owner email is invalid.', self::EXIT_PREFLIGHT);
        }

        $user = User::query()->where('email', $email)->first();

        if ($user) {
            // Re-install never resets an existing password.
            $user->forceFill(['is_platform_admin' => true])->save();
            $this->report['owner'] = 'existing';
        } else {
            $user = User::query()->create([
                'name' => $this->option('admin-name'),
                'email' => $email,
                'password' => $this->password(),
            ]);
            $user->forceFill(['is_platform_admin' => true])->save();
            $this->report['owner'] = 'created';
        }

        if (! TenantMembership::query()->where('user_id', $user->id)->exists()) {
            app(TenantProvisioner::class)->create($user, (string) $this->option('organization'));
        }
    }

    private function password(): string
    {
        if ($file = $this->option('admin-password-file')) {
            if (! is_file($file) || (fileperms($file) & 0077) !== 0) {
                throw new OperationFailed('password_file_insecure', 'Password file must exist and be owner-readable only (chmod 600).', self::EXIT_PREFLIGHT);
            }

            $password = trim((string) file_get_contents($file));
        } elseif ($this->option('non-interactive')) {
            throw new OperationFailed('password_required', 'Non-interactive mode requires --admin-password-file.', self::EXIT_PREFLIGHT);
        } else {
            $password = (string) $this->secret('Owner password (hidden)');
        }

        if (strlen($password) < 8) {
            throw new OperationFailed('password_weak', 'Owner password must be at least 8 characters.', self::EXIT_PREFLIGHT);
        }

        return $password;
    }

    private function seedDemo(): void
    {
        $membership = TenantMembership::query()->where('role', 'owner')
            ->whereHas('user', fn ($q) => $q->where('is_platform_admin', true))->oldest()->first();

        if (! $membership) {
            return;
        }

        $tenantId = $membership->tenant_id;
        $property = Property::query()->withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenantId, 'name' => 'Demo Property'],
            ['address' => 'Demo data', 'timezone' => 'Asia/Jakarta'],
        );
        $kitchen = Kitchen::query()->withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenantId, 'property_id' => $property->id, 'name' => 'Demo Kitchen'],
            ['description' => 'Created by system:install --demo'],
        );
        MediaSource::query()->withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenantId, 'kitchen_id' => $kitchen->id, 'name' => 'Upload Demo'],
            ['type' => 'upload', 'status' => 'active'],
        );
    }

    private function verify(): void
    {
        $status = app(SystemStatus::class);
        $modified = ($release = SystemRelease::query()->where('app_version', app(ReleaseManifest::class)->version())->first())
            ? $status->modifiedMigrations($release) : [];

        if ($modified !== []) {
            throw new OperationFailed('migration_modified', 'Installed migration(s) changed: '.implode(', ', $modified));
        }

        try {
            Queue::connection()->size();
        } catch (Throwable $e) {
            throw new OperationFailed('queue_unavailable', 'Queue is unavailable: '.$e->getMessage());
        }

        $health = $status->collect();
        $this->report['analysis_ready'] = $health['analysis_ready'];
        $this->report['worker'] = $health['services']['worker'] ? 'healthy' : 'unreachable';

        if (! $health['analysis_ready']) {
            $this->components->warn('AI worker unreachable: dashboard is ready, analysis_ready=false.');
        }

        Artisan::call('optimize:clear');
    }
}
