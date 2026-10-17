<?php

namespace App\Http\Controllers\Internal;

use App\Domain\Uptime\DeviceMonitor;
use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceHeartbeatController extends Controller
{
    public function __invoke(Request $request, DeviceMonitor $monitor): JsonResponse
    {
        $token = (string) $request->bearerToken();

        $device = $token === '' ? null : Device::query()->withoutGlobalScopes()
            ->where('check_type', 'heartbeat')
            ->where('heartbeat_token_hash', hash('sha256', $token))
            ->first();

        abort_if($device === null, 401, 'Invalid device token.');

        $meta = $request->validate([
            'version' => ['nullable', 'string', 'max:32'],
            'uptime_seconds' => ['nullable', 'integer', 'min:0'],
            'cpu_percent' => ['nullable', 'numeric', 'between:0,100'],
            'memory_percent' => ['nullable', 'numeric', 'between:0,100'],
            'disk_percent' => ['nullable', 'numeric', 'between:0,100'],
            'temperature_c' => ['nullable', 'numeric', 'between:-40,150'],
        ]);

        $monitor->heartbeat($device, array_filter($meta, fn ($v) => $v !== null));

        return response()->json(['ok' => true, 'interval_seconds' => $device->interval_seconds]);
    }
}
