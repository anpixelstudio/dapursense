<?php

namespace App\Domain\Uptime;

use App\Domain\Events\SystemEvents;
use App\Domain\System\SystemStatus;
use App\Enums\Permission;
use App\Models\Device;
use App\Models\DeviceIncident;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Notifications\DeviceStatusChanged;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Only state changes become incidents, events and notifications; individual
 * checks are folded into one stats row per device per hour.
 */
class DeviceMonitor
{
    public const SYSTEM_COMPONENTS = [
        'database' => 'Database',
        'redis' => 'Antrean (Redis)',
        'worker' => 'Worker AI',
        'storage' => 'Penyimpanan',
    ];

    public function __construct(private TcpProber $prober, private SystemStatus $system) {}

    public function ensureSystemDevices(string $tenantId): void
    {
        $existing = Device::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('check_type', 'system')->pluck('target')->all();

        foreach (array_diff_key(self::SYSTEM_COMPONENTS, array_flip($existing)) as $target => $name) {
            Device::query()->withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'name' => $name,
                'kind' => 'server',
                'check_type' => 'system',
                'target' => $target,
            ]);
        }
    }

    public function checkDue(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();

        $missing = Tenant::query()->whereNotIn('id', Device::query()->withoutGlobalScopes()
            ->where('check_type', 'system')->groupBy('tenant_id')
            ->havingRaw('COUNT(*) >= ?', [count(self::SYSTEM_COMPONENTS)])->select('tenant_id'))->pluck('id');
        $missing->each(fn (string $id) => $this->ensureSystemDevices($id));

        // A few seconds of slack so a scheduler tick that starts late does not skip a check.
        $due = Device::query()->withoutGlobalScopes()
            ->where('paused', false)
            // An edge box that never reported is not installed yet, so it has no uptime to lose.
            ->where(fn ($q) => $q->where('check_type', '!=', 'heartbeat')->orWhereNotNull('last_seen_at'))
            ->where(fn ($q) => $q->whereNull('last_checked_at')
                ->orWhereRaw('last_checked_at <= ?::timestamptz - make_interval(secs => GREATEST(interval_seconds - 5, 0))', [$now]))
            ->get();

        if ($due->isEmpty()) {
            return 0;
        }

        $results = $this->tcpResults($due->where('check_type', 'tcp'))
            + $this->heartbeatResults($due->where('check_type', 'heartbeat'), $now)
            + $this->systemResults($due->where('check_type', 'system'));

        $this->record($due, $results, $now);

        return count($results);
    }

    /** @param array<string, mixed> $meta */
    public function heartbeat(Device $device, array $meta): void
    {
        $now = CarbonImmutable::now();
        $device->forceFill(['last_seen_at' => $now, 'meta' => $meta ?: $device->meta])->save();

        if ($device->status !== 'up' && ! $device->paused) {
            $this->record(collect([$device]), [$device->id => ['ok' => true, 'latency_ms' => null, 'error' => null]], $now);
        }
    }

    /**
     * @param  Collection<int, Device>  $devices
     * @param  array<string, array{ok: bool, latency_ms: int|null, error: string|null}>  $results
     */
    public function record(Collection $devices, array $results, CarbonImmutable $now): void
    {
        $rows = [];
        $stats = [];
        $transitions = [];
        $hour = $now->startOfHour();

        foreach ($devices as $device) {
            $result = $results[$device->id] ?? null;

            if ($result === null) {
                continue;
            }

            $failures = $result['ok'] ? 0 : $device->consecutive_failures + 1;
            $status = match (true) {
                $result['ok'] => 'up',
                $failures >= $device->failure_threshold => 'down',
                default => $device->status,
            };
            $changed = $status !== $device->status;

            $rows[] = [
                $device->id, $status, $failures, $result['latency_ms'], $result['error'],
                ($changed ? $now : $device->status_changed_at)?->toIso8601String(),
            ];
            $stats[] = [
                'tenant_id' => $device->tenant_id,
                'device_id' => $device->id,
                'hour' => $hour,
                'checks' => 1,
                'failures' => $result['ok'] ? 0 : 1,
                'latency_sum' => $result['latency_ms'] ?? 0,
                'latency_count' => $result['latency_ms'] === null ? 0 : 1,
                'latency_max' => $result['latency_ms'] ?? 0,
            ];

            if ($changed) {
                $transitions[] = [$device, $status, $result];
            }
        }

        if ($rows === []) {
            return;
        }

        DB::transaction(function () use ($rows, $stats, $now) {
            foreach (array_chunk($rows, 500) as $chunk) {
                $values = implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?)'));
                DB::update(
                    "UPDATE devices AS d SET status = v.status, consecutive_failures = v.failures::int,
                        last_latency_ms = v.latency::int, last_error = v.error, status_changed_at = v.changed::timestamptz,
                        last_checked_at = ?, updated_at = ?
                     FROM (VALUES {$values}) AS v(id, status, failures, latency, error, changed)
                     WHERE d.id = v.id::uuid",
                    [$now, $now, ...array_merge(...$chunk)],
                );
            }

            DB::table('device_stats_hourly')->upsert($stats, ['device_id', 'hour'], [
                'checks' => DB::raw('device_stats_hourly.checks + excluded.checks'),
                'failures' => DB::raw('device_stats_hourly.failures + excluded.failures'),
                'latency_sum' => DB::raw('device_stats_hourly.latency_sum + excluded.latency_sum'),
                'latency_count' => DB::raw('device_stats_hourly.latency_count + excluded.latency_count'),
                'latency_max' => DB::raw('GREATEST(device_stats_hourly.latency_max, excluded.latency_max)'),
            ]);
        });

        foreach ($transitions as [$device, $status, $result]) {
            $this->transition($device, $status, $result, $now);
        }
    }

    /** @param array{ok: bool, latency_ms: int|null, error: string|null} $result */
    private function transition(Device $device, string $status, array $result, CarbonImmutable $now): void
    {
        if ($status === 'down') {
            $incident = DB::transaction(function () use ($device, $result, $now) {
                $incident = DeviceIncident::query()->withoutGlobalScopes()->create([
                    'tenant_id' => $device->tenant_id,
                    'device_id' => $device->id,
                    'started_at' => $now,
                    'reason' => $result['error'],
                ]);
                $event = SystemEvents::forDevice($device, $incident, $result['error']);
                $incident->update(['event_id' => $event->id]);

                return $incident;
            });

            $this->notify($device, false, $incident);

            return;
        }

        if ($status === 'up') {
            $incident = DeviceIncident::query()->withoutGlobalScopes()
                ->where('device_id', $device->id)->whereNull('ended_at')->latest('started_at')->first();

            if ($incident === null) {
                return;
            }

            $incident->update(['ended_at' => $now]);
            $this->notify($device, true, $incident);
        }
    }

    private function notify(Device $device, bool $up, DeviceIncident $incident): void
    {
        $recipients = TenantMembership::query()->where('tenant_id', $device->tenant_id)->where('status', 'active')
            ->with('user')->get()
            ->filter(fn ($m) => $m->role->can(Permission::ManageSources))
            ->pluck('user');

        Notification::send($recipients, new DeviceStatusChanged($device, $up, $incident));
    }

    /**
     * @param  Collection<int, Device>  $devices
     * @return array<string, array{ok: bool, latency_ms: int|null, error: string|null}>
     */
    private function tcpResults(Collection $devices): array
    {
        if ($devices->isEmpty()) {
            return [];
        }

        return $this->prober->probe(
            $devices->mapWithKeys(fn (Device $d) => [$d->id => [$d->host, (int) $d->port]])->all(),
            (float) config('kitchen.uptime.tcp_timeout_seconds', 3),
        );
    }

    /**
     * @param  Collection<int, Device>  $devices
     * @return array<string, array{ok: bool, latency_ms: int|null, error: string|null}>
     */
    private function heartbeatResults(Collection $devices, CarbonImmutable $now): array
    {
        return $devices->mapWithKeys(function (Device $d) use ($now) {
            $grace = $d->interval_seconds + max(15, intdiv($d->interval_seconds, 2));
            $ok = $d->last_seen_at !== null && $d->last_seen_at->greaterThanOrEqualTo($now->subSeconds($grace));

            return [$d->id => ['ok' => $ok, 'latency_ms' => null, 'error' => $ok ? null : 'no_heartbeat']];
        })->all();
    }

    /**
     * @param  Collection<int, Device>  $devices
     * @return array<string, array{ok: bool, latency_ms: int|null, error: string|null}>
     */
    private function systemResults(Collection $devices): array
    {
        if ($devices->isEmpty()) {
            return [];
        }

        $started = hrtime(true);
        $services = $this->system->collect()['services'];
        $latency = (int) round((hrtime(true) - $started) / 1e6);

        $state = [
            'database' => $services['database'],
            'redis' => $services['redis'],
            'worker' => (bool) ($services['worker']['ok'] ?? false),
            'storage' => ! $services['storage_blocked'],
        ];

        return $devices->mapWithKeys(fn (Device $d) => [$d->id => [
            'ok' => $state[$d->target] ?? false,
            'latency_ms' => $d->target === 'worker' ? $latency : null,
            'error' => ($state[$d->target] ?? false) ? null : ($d->target === 'storage' ? 'storage_full' : 'unavailable'),
        ]])->all();
    }
}
