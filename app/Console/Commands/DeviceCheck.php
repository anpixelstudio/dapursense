<?php

namespace App\Console\Commands;

use App\Domain\Uptime\DeviceMonitor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('devices:check')]
#[Description('Check due device uptime (TCP, heartbeat, server components)')]
class DeviceCheck extends Command
{
    public function handle(DeviceMonitor $monitor): int
    {
        $this->info('Devices checked: '.$monitor->checkDue());

        return self::SUCCESS;
    }
}
