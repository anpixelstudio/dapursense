<?php

namespace App\Console\Commands;

use App\Domain\System\SystemStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('system:status {--json : Keluaran JSON}')]
#[Description('Tampilkan versi, status schema, layanan dan kesiapan analisis')]
class SystemStatusCommand extends Command
{
    public function handle(SystemStatus $status): int
    {
        $data = $status->collect();

        if ($this->option('json')) {
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Versi aplikasi', $data['app_version'].($data['tagged_release'] ? '' : ' (dev)'));
        $this->components->twoColumnDetail('Terinstal', $data['installed'] ? 'ya ('.$data['schema']['installed_version'].')' : 'belum');
        $this->components->twoColumnDetail('Migration tertunda', (string) count($data['schema']['pending_migrations']));
        $this->components->twoColumnDetail('Migration diubah', (string) count($data['schema']['modified_migrations']));
        $this->components->twoColumnDetail('Database', $data['services']['database'] ? 'OK' : 'GAGAL');
        $this->components->twoColumnDetail('Redis', $data['services']['redis'] ? 'OK' : 'GAGAL');
        $this->components->twoColumnDetail('Storage terpakai', $data['services']['storage_used_percent'].'%');
        $this->components->twoColumnDetail('Worker AI', $data['services']['worker'] ? 'OK' : 'tidak terhubung');
        $this->components->twoColumnDetail('Analysis ready', $data['analysis_ready'] ? 'ya' : 'tidak');

        return self::SUCCESS;
    }
}
