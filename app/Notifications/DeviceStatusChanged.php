<?php

namespace App\Notifications;

use App\Models\Device;
use App\Models\DeviceIncident;
use Illuminate\Notifications\Notification;

class DeviceStatusChanged extends Notification
{
    public function __construct(private Device $device, private bool $up, private DeviceIncident $incident) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $target = $this->device->host ? " ({$this->device->host}:{$this->device->port})" : '';
        $minutes = $this->incident->ended_at
            ? max(1, (int) round($this->incident->started_at->diffInMinutes($this->incident->ended_at)))
            : null;

        return [
            'tenant_id' => $this->device->tenant_id,
            'kind' => $this->up ? 'device_up' : 'device_down',
            'title' => $this->up
                ? "{$this->device->name} back online"
                : "{$this->device->name} offline",
            'body' => $this->up
                ? "Device{$target} recovered after about {$minutes} minute(s) offline."
                : "Device{$target} failed to respond {$this->device->failure_threshold} time(s) in a row.",
            'url' => route('uptime.index', [], false),
        ];
    }
}
