<?php

namespace App\Console\Commands;

use App\Domain\Uptime\DeviceMonitor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('devices:check')]
#[Description('Cek uptime perangkat yang sudah jatuh tempo (TCP, heartbeat, komponen server)')]
class DeviceCheck extends Command
{
    public function handle(DeviceMonitor $monitor): int
    {
        $this->info('Perangkat dicek: '.$monitor->checkDue());

        return self::SUCCESS;
    }
}
