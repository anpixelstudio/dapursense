<?php

namespace App\Http\Controllers;

use App\Domain\Uptime\DeviceMonitor;
use App\Enums\Permission;
use App\Models\Device;
use App\Models\DeviceIncident;
use App\Models\Kitchen;
use App\Models\MediaSource;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UptimeController extends Controller
{
    private const KINDS = ['camera', 'nvr', 'edge', 'network'];

    private const INTERVALS = [60, 300, 900];

    public function index(DeviceMonitor $monitor): Response
    {
        $this->authorizePermission(Permission::ViewData);
        $monitor->ensureSystemDevices($this->tenantId());

        $now = CarbonImmutable::now();
        $since = $now->startOfHour()->subHours(23);
        $stats = DB::table('device_stats_hourly')->where('tenant_id', $this->tenantId());

        $hourly = (clone $stats)->where('hour', '>=', $since)
            ->get(['device_id', 'hour', 'checks', 'failures'])
            ->groupBy('device_id')
            ->map(fn ($rows) => $rows->keyBy(fn ($r) => CarbonImmutable::parse($r->hour)->getTimestamp()));

        $totals = (clone $stats)->where('hour', '>=', $now->subDays(30))
            ->groupBy('device_id')
            ->selectRaw('device_id,
                SUM(checks) FILTER (WHERE hour >= ?) AS c1, SUM(failures) FILTER (WHERE hour >= ?) AS f1,
                SUM(checks) FILTER (WHERE hour >= ?) AS c7, SUM(failures) FILTER (WHERE hour >= ?) AS f7,
                SUM(checks) AS c30, SUM(failures) AS f30,
                SUM(latency_sum) FILTER (WHERE hour >= ?) AS ls, SUM(latency_count) FILTER (WHERE hour >= ?) AS lc',
                [$since, $since, $now->subDays(7), $now->subDays(7), $since, $since])
            ->get()
            ->keyBy('device_id');

        $devices = Device::query()
            ->with(['kitchen:id,name,property_id', 'kitchen.property:id,name', 'source:id,name'])
            ->orderByRaw("check_type = 'system'")
            ->orderBy('name')
            ->get()
            ->map(function (Device $device) use ($hourly, $totals, $since) {
                $rows = $hourly->get($device->id, collect());
                $t = $totals->get($device->id);

                return $device->toArray() + [
                    'bars' => collect(range(0, 23))->map(function (int $i) use ($rows, $since) {
                        $hour = $since->addHours($i);
                        $row = $rows->get($hour->getTimestamp());

                        return ['hour' => $hour->toIso8601String(), 'uptime' => $row ? $this->percent($row->checks, $row->failures) : null];
                    })->all(),
                    'uptime_24h' => $t ? $this->percent($t->c1, $t->f1) : null,
                    'uptime_7d' => $t ? $this->percent($t->c7, $t->f7) : null,
                    'uptime_30d' => $t ? $this->percent($t->c30, $t->f30) : null,
                    'avg_latency_ms' => $t && $t->lc > 0 ? (int) round($t->ls / $t->lc) : null,
                ];
            });

        $active = $devices->where('paused', false);

        return Inertia::render('uptime/index', [
            'devices' => $devices->values(),
            'summary' => [
                'total' => $devices->count(),
                'up' => $active->where('status', 'up')->count(),
                'down' => $active->where('status', 'down')->count(),
                'unknown' => $active->where('status', 'unknown')->count(),
                'paused' => $devices->where('paused', true)->count(),
                'uptime_24h' => $this->percent($totals->sum('c1'), $totals->sum('f1')),
            ],
            'incidents' => DeviceIncident::query()->with('device:id,name,kind')
                ->latest('started_at')->limit(20)
                ->get(['id', 'device_id', 'event_id', 'started_at', 'ended_at', 'reason']),
            'kitchens' => Kitchen::query()->with('property:id,name')->orderBy('name')->get(['id', 'name', 'property_id']),
            'sources' => MediaSource::query()->orderBy('name')->get(['id', 'name', 'kitchen_id']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizePermission(Permission::ManageSources);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::in(self::KINDS)],
            'kitchen_id' => ['nullable', 'uuid', Rule::exists('kitchens', 'id')->where('tenant_id', $this->tenantId())],
            'source_id' => ['nullable', 'uuid', Rule::exists('media_sources', 'id')->where('tenant_id', $this->tenantId())],
            ...$this->targetRules(required: true),
            'interval_seconds' => ['required', 'integer', Rule::in(self::INTERVALS)],
            'failure_threshold' => ['nullable', 'integer', 'between:1,10'],
        ], $this->messages());

        $heartbeat = $data['kind'] === 'edge';
        $token = $heartbeat ? Str::random(48) : null;

        $device = Device::query()->create([
            ...$data,
            'check_type' => $heartbeat ? 'heartbeat' : 'tcp',
            'host' => $heartbeat ? null : $data['host'],
            'port' => $heartbeat ? null : $data['port'],
            'failure_threshold' => $data['failure_threshold'] ?? 3,
            'heartbeat_token_hash' => $token ? hash('sha256', $token) : null,
        ]);

        Audit::log('device.created', $device);
        $this->flashToken($device, $token);
        $this->toast('Perangkat ditambahkan; pengecekan pertama berjalan dalam 1 menit.');

        return back();
    }

    public function update(Request $request, Device $device): RedirectResponse
    {
        $this->authorizePermission(Permission::ManageSources, $device);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            ...($device->check_type === 'tcp' ? $this->targetRules(required: false) : []),
            'interval_seconds' => ['sometimes', 'integer', Rule::in(self::INTERVALS)],
            'failure_threshold' => ['sometimes', 'integer', 'between:1,10'],
            'paused' => ['sometimes', 'boolean'],
        ], $this->messages());

        if (($data['paused'] ?? null) === false && $device->paused) {
            $data['last_checked_at'] = null;
        }

        $device->update($data);
        Audit::log('device.updated', $device, $device->getChanges());
        $this->toast('Perangkat diperbarui.');

        return back();
    }

    public function rotateToken(Device $device): RedirectResponse
    {
        $this->authorizePermission(Permission::ManageSources, $device);
        abort_unless($device->check_type === 'heartbeat', 422);

        $token = Str::random(48);
        $device->update(['heartbeat_token_hash' => hash('sha256', $token)]);
        Audit::log('device.token_rotated', $device);
        $this->flashToken($device, $token);

        return back();
    }

    public function destroy(Device $device): RedirectResponse
    {
        $this->authorizePermission(Permission::ManageSources, $device);

        if ($device->check_type === 'system') {
            return back()->withErrors(['device' => 'Komponen server dipantau otomatis dan tidak dapat dihapus; jeda saja bila perlu.']);
        }

        DB::transaction(function () use ($device) {
            DB::table('device_stats_hourly')->where('device_id', $device->id)->delete();
            $device->incidents()->delete();
            $device->delete();
        });

        Audit::log('device.deleted', $device);
        $this->toast('Perangkat dihapus.');

        return back();
    }

    /** @return array<string, list<mixed>> */
    private function targetRules(bool $required): array
    {
        $presence = $required ? ['exclude_if:kind,edge', 'required'] : ['sometimes'];

        return [
            // Link-local, multicast and "this network" ranges are never device addresses.
            'host' => [...$presence, 'string', 'max:253', 'regex:/^[A-Za-z0-9.\-:]+$/', 'not_regex:/^(0\.|169\.254\.|22[4-9]\.|2[3-5]\d\.)/'],
            'port' => [...$presence, 'integer', 'between:1,65535'],
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'host.regex' => 'Isi alamat IP atau hostname tanpa http:// atau path.',
            'host.not_regex' => 'Alamat ini bukan alamat perangkat yang valid.',
        ];
    }

    private function flashToken(Device $device, ?string $token): void
    {
        if ($token !== null) {
            Inertia::flash('device_token', [
                'device' => $device->name,
                'token' => $token,
                'url' => route('devices.heartbeat'),
                'interval_seconds' => $device->interval_seconds,
            ]);
        }
    }

    private function percent(int|string|null $checks, int|string|null $failures): ?float
    {
        $checks = (int) $checks;

        return $checks > 0 ? round(100 * ($checks - (int) $failures) / $checks, 2) : null;
    }
}
