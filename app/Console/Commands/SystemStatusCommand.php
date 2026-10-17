<?php

namespace App\Console\Commands;

use App\Domain\System\SystemStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('system:status {--json : JSON output}')]
#[Description('Show version, schema status, services, and analysis readiness')]
class SystemStatusCommand extends Command
{
    public function handle(SystemStatus $status): int
    {
        $data = $status->collect();

        if ($this->option('json')) {
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('App version', $data['app_version'].($data['tagged_release'] ? '' : ' (dev)'));
        $this->components->twoColumnDetail('Installed', $data['installed'] ? 'yes ('.$data['schema']['installed_version'].')' : 'no');
        $this->components->twoColumnDetail('Pending migrations', (string) count($data['schema']['pending_migrations']));
        $this->components->twoColumnDetail('Modified migrations', (string) count($data['schema']['modified_migrations']));
        $this->components->twoColumnDetail('Database', $data['services']['database'] ? 'OK' : 'FAILED');
        $this->components->twoColumnDetail('Redis', $data['services']['redis'] ? 'OK' : 'FAILED');
        $this->components->twoColumnDetail('Storage used', $data['services']['storage_used_percent'].'%');
        $this->components->twoColumnDetail('Worker AI', $data['services']['worker'] ? 'OK' : 'unreachable');
        $this->components->twoColumnDetail('Analysis ready', $data['analysis_ready'] ? 'yes' : 'no');

        return self::SUCCESS;
    }
}
