<?php

namespace App\Domain\Events;

use App\Enums\EventCategory;
use App\Models\AnalysisRun;
use App\Models\AnomalyEvent;
use App\Models\Device;
use App\Models\DeviceIncident;

/**
 * Operational anomalies (stalled worker, unreadable source, low storage).
 * They never become PPE violations.
 */
class SystemEvents
{
    /** @param array<string, mixed> $evidence */
    public static function forRun(AnalysisRun $run, string $type, string $dedupeSuffix, array $evidence = []): AnomalyEvent
    {
        return AnomalyEvent::query()->withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $run->tenant_id, 'dedupe_key' => hash('sha256', "system|{$run->id}|{$type}|{$dedupeSuffix}")],
            [
                'run_id' => $run->id,
                'source_id' => $run->source_id,
                'type' => $type,
                'category' => EventCategory::System,
                'severity' => 'system',
                'lifecycle' => 'closed',
                'occurred_at' => now(),
                'is_simulation' => $run->isSimulation(),
                'evidence' => $evidence,
            ],
        );
    }

    public static function forDevice(Device $device, DeviceIncident $incident, ?string $error): AnomalyEvent
    {
        return AnomalyEvent::query()->withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $device->tenant_id, 'dedupe_key' => hash('sha256', "device|{$device->id}|{$incident->id}")],
            [
                'source_id' => $device->source_id,
                'type' => 'device_offline',
                'category' => EventCategory::System,
                'severity' => 'system',
                'lifecycle' => 'closed',
                'occurred_at' => $incident->started_at,
                'evidence' => [
                    'device_id' => $device->id,
                    'device_name' => $device->name,
                    'kind' => $device->kind,
                    'host' => $device->host,
                    'port' => $device->port,
                    'error' => $error,
                    'failures' => $device->consecutive_failures + 1,
                ],
            ],
        );
    }
}
