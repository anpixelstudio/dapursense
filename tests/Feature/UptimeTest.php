<?php

use App\Domain\Uptime\DeviceMonitor;
use App\Domain\Uptime\TcpProber;
use App\Enums\Role;
use App\Models\AnomalyEvent;
use App\Models\Device;
use App\Models\DeviceIncident;
use App\Notifications\DeviceStatusChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::fake(['ai-worker.test/*' => Http::response(['ok' => true])]);
    $this->owner = tenantUser();
    $this->tenant = tenantOf($this->owner);
});

function closedPort(): int
{
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) explode(':', stream_socket_get_name($server, false))[1];
    fclose($server);

    return $port;
}

function tcpDevice(string $tenantId, int $port, array $attributes = []): Device
{
    return Device::query()->withoutGlobalScopes()->create($attributes + [
        'tenant_id' => $tenantId,
        'name' => 'Kamera Pass',
        'kind' => 'camera',
        'check_type' => 'tcp',
        'host' => '127.0.0.1',
        'port' => $port,
    ]);
}

test('the prober checks open and closed ports in parallel', function () {
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $open = (int) explode(':', stream_socket_get_name($server, false))[1];

    $results = app(TcpProber::class)->probe(['open' => ['127.0.0.1', $open], 'closed' => ['127.0.0.1', closedPort()]], 2);
    fclose($server);

    expect($results['open']['ok'])->toBeTrue()
        ->and($results['open']['latency_ms'])->toBeInt()
        ->and($results['closed']['ok'])->toBeFalse();
});

test('a device goes down only after consecutive failures and recovers with an incident', function () {
    Notification::fake();
    $device = tcpDevice($this->tenant->id, closedPort());

    $this->artisan('devices:check')->assertSuccessful();
    expect($device->fresh())->status->toBe('unknown')->consecutive_failures->toBe(1);

    Device::query()->withoutGlobalScopes()->whereKey($device->id)->update(['last_checked_at' => now()->subMinutes(2)]);
    $this->artisan('devices:check');
    Device::query()->withoutGlobalScopes()->whereKey($device->id)->update(['last_checked_at' => now()->subMinutes(2)]);
    $this->artisan('devices:check');

    $device->refresh();
    $incident = DeviceIncident::query()->withoutGlobalScopes()->sole();
    $event = AnomalyEvent::query()->withoutGlobalScopes()->sole();

    expect($device->status)->toBe('down')
        ->and($incident->ended_at)->toBeNull()
        ->and($incident->event_id)->toBe($event->id)
        ->and($event->type)->toBe('device_offline')
        ->and($event->source_id)->toBeNull()
        ->and($event->evidence['device_name'])->toBe('Kamera Pass')
        ->and(DB::table('device_stats_hourly')->where('device_id', $device->id)->value('failures'))->toBe(3);

    Notification::assertSentTo($this->owner, DeviceStatusChanged::class, fn ($n) => $n->toArray($this->owner)['kind'] === 'device_down');

    $server = stream_socket_server('tcp://127.0.0.1:'.$device->port);
    Device::query()->withoutGlobalScopes()->whereKey($device->id)->update(['last_checked_at' => now()->subMinutes(2)]);
    $this->artisan('devices:check');
    fclose($server);

    expect($device->fresh()->status)->toBe('up')
        ->and($incident->fresh()->ended_at)->not->toBeNull();
    Notification::assertSentTo($this->owner, DeviceStatusChanged::class, fn ($n) => $n->toArray($this->owner)['kind'] === 'device_up');
});

test('devices are only checked when their interval is due and never while paused', function () {
    $due = tcpDevice($this->tenant->id, closedPort(), ['last_checked_at' => now()->subMinutes(6), 'interval_seconds' => 300]);
    $notDue = tcpDevice($this->tenant->id, closedPort(), ['last_checked_at' => now()->subMinutes(2), 'interval_seconds' => 300]);
    $paused = tcpDevice($this->tenant->id, closedPort(), ['paused' => true]);

    app(DeviceMonitor::class)->checkDue();

    expect($due->fresh()->consecutive_failures)->toBe(1)
        ->and($notDue->fresh()->consecutive_failures)->toBe(0)
        ->and($paused->fresh()->last_checked_at)->toBeNull();
});

test('server components are provisioned and checked for every tenant', function () {
    $this->artisan('devices:check')->assertSuccessful();

    $system = Device::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('check_type', 'system')->get();

    expect($system->pluck('target')->sort()->values()->all())->toBe(['database', 'redis', 'storage', 'worker'])
        ->and($system->firstWhere('target', 'database')->status)->toBe('up');
});

test('an edge box reports with its token and is marked down when heartbeats stop', function () {
    $this->actingAs($this->owner)->post('/devices', [
        'name' => 'Mini PC Dapur', 'kind' => 'edge', 'interval_seconds' => 60,
    ])->assertSessionHasNoErrors()->assertInertiaFlash('device_token.device', 'Mini PC Dapur');

    $device = Device::query()->withoutGlobalScopes()->where('kind', 'edge')->sole();
    $token = session('inertia.flash_data')['device_token']['token'] ?? null;

    app(DeviceMonitor::class)->checkDue();
    expect($device->fresh())->status->toBe('unknown')->last_checked_at->toBeNull();

    $this->postJson('/api/devices/heartbeat', [], ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
    $this->postJson('/api/devices/heartbeat', ['cpu_percent' => 12.5, 'temperature_c' => 48], ['Authorization' => "Bearer {$token}"])
        ->assertOk()->assertJson(['ok' => true, 'interval_seconds' => 60]);

    expect($device->fresh())->status->toBe('up')->meta->toBe(['cpu_percent' => 12.5, 'temperature_c' => 48]);

    Device::query()->withoutGlobalScopes()->whereKey($device->id)->update(['last_seen_at' => now()->subMinutes(10), 'last_checked_at' => null, 'failure_threshold' => 1]);
    app(DeviceMonitor::class)->checkDue();

    expect($device->fresh())->status->toBe('down')->last_error->toBe('no_heartbeat');
});

test('the uptime page lists devices with hourly bars and incidents', function () {
    $device = tcpDevice($this->tenant->id, closedPort(), ['failure_threshold' => 1]);
    app(DeviceMonitor::class)->checkDue();

    $this->actingAs($this->owner)->get('/uptime')->assertInertia(fn (Assert $page) => $page
        ->component('uptime/index')
        ->has('devices', 5)
        ->where('devices.0.id', $device->id)
        ->has('devices.0.bars', 24)
        ->where('devices.0.uptime_24h', 0)
        ->where('summary.down', 1)
        ->has('incidents', 1)
        ->missing('devices.0.heartbeat_token_hash'));
});

test('device input is validated and system components cannot be deleted', function () {
    $this->actingAs($this->owner)->post('/devices', [
        'name' => 'X', 'kind' => 'camera', 'host' => 'http://169.254.169.254/latest', 'port' => 99999, 'interval_seconds' => 45,
    ])->assertSessionHasErrors(['host', 'port', 'interval_seconds']);

    $this->actingAs($this->owner)->post('/devices', [
        'name' => 'X', 'kind' => 'camera', 'host' => '169.254.169.254', 'port' => 80, 'interval_seconds' => 60,
    ])->assertSessionHasErrors(['host']);

    $this->actingAs($this->owner)->get('/uptime');
    $system = Device::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('check_type', 'system')->first();

    $this->actingAs($this->owner)->delete("/devices/{$system->id}")->assertSessionHasErrors('device');
    expect($system->fresh())->not->toBeNull();
});

test('operators can view uptime but not manage devices, and tenants are isolated', function () {
    $operator = tenantUser(Role::Operator, $this->tenant);
    $other = tcpDevice(tenantOf(tenantUser())->id, 554);

    $this->actingAs($operator)->get('/uptime')->assertOk();
    $this->actingAs($operator)->post('/devices', ['name' => 'X', 'kind' => 'camera', 'host' => '10.0.0.5', 'port' => 554, 'interval_seconds' => 60])->assertForbidden();
    $this->actingAs($this->owner)->delete("/devices/{$other->id}")->assertNotFound();
});
