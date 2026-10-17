import { Head, Link, router, useForm, usePoll } from '@inertiajs/react';
import { KeyRound, Pause, Play, Trash2, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { ConfirmDialog } from '@/components/kitchen/confirm-dialog';
import {
    EmptyState,
    Field,
    NativeSelect,
    Page,
    PageErrors,
    PageHeader,
    Pill,
    Stat,
    Table,
    Td,
    Th,
} from '@/components/kitchen/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { deviceErrorLabel, formatDateTime, useTenant } from '@/lib/kitchen';
import { cn } from '@/lib/utils';
import devices from '@/routes/devices';
import events from '@/routes/events';
import uptime from '@/routes/uptime';
import type { Device, DeviceIncident, Kitchen } from '@/types/kitchen';

type Summary = {
    total: number;
    up: number;
    down: number;
    unknown: number;
    paused: number;
    uptime_24h: number | null;
};

type DeviceToken = {
    device: string;
    token: string;
    url: string;
    interval_seconds: number;
};

const KIND_LABELS: Record<string, string> = {
    camera: 'CCTV camera',
    nvr: 'NVR / DVR',
    edge: 'Edge box (heartbeat)',
    network: 'Network device',
    server: 'Server',
};

const DEFAULT_PORT: Record<string, string> = {
    camera: '554',
    nvr: '80',
    network: '80',
    edge: '',
};

function formatDuration(ms: number): string {
    const minutes = Math.floor(ms / 60000);

    if (minutes < 1) {
        return '< 1 min';
    }

    const h = Math.floor(minutes / 60);
    const d = Math.floor(h / 24);

    if (d > 0) {
        return `${d}d ${h % 24}h`;
    }

    return h > 0 ? `${h}h ${minutes % 60}m` : `${minutes} min`;
}

function since(value: string | null): string {
    return value ? formatDuration(Date.now() - new Date(value).getTime()) : '-';
}

const formatUptime = (value: number | null) =>
    value === null ? '-' : `${value.toFixed(value === 100 ? 0 : 2)}%`;

const uptimeTone = (value: number | null) =>
    value === null
        ? 'bg-muted'
        : value >= 99.5
          ? 'bg-emerald-500'
          : value >= 90
            ? 'bg-amber-500'
            : 'bg-red-500';

function StatusDot({ device }: { device: Device }) {
    if (device.paused) {
        return <Pill tone="neutral">Paused</Pill>;
    }

    return device.status === 'up' ? (
        <Pill tone="success">Online</Pill>
    ) : device.status === 'down' ? (
        <Pill tone="danger">Offline</Pill>
    ) : (
        <Pill tone="neutral">Not checked yet</Pill>
    );
}

function UptimeBars({ device }: { device: Device }) {
    const { tenant } = useTenant();

    return (
        <div
            className="flex h-6 items-end gap-px"
            data-test="uptime-bars"
            aria-label={`Uptime last 24 hours ${formatUptime(device.uptime_24h)}`}
        >
            {device.bars.map((b) => (
                <span
                    key={b.hour}
                    title={`${formatDateTime(b.hour, tenant?.timezone)} · ${b.uptime === null ? 'no data' : formatUptime(b.uptime)}`}
                    className={cn(
                        'h-full w-1.5 rounded-sm',
                        uptimeTone(b.uptime),
                    )}
                />
            ))}
        </div>
    );
}

function target(device: Device): string {
    if (device.check_type === 'heartbeat') {
        return device.last_seen_at
            ? `heartbeat ${since(device.last_seen_at)} ago`
            : 'awaiting first heartbeat';
    }

    if (device.check_type === 'system') {
        return 'server component';
    }

    return `${device.host}:${device.port}`;
}

function DeviceTable({
    list,
    canManage,
}: {
    list: Device[];
    canManage: boolean;
}) {
    const [tokenDevice, setTokenDevice] = useState<Device | null>(null);
    const [deleteDevice, setDeleteDevice] = useState<Device | null>(null);

    const togglePause = (d: Device) =>
        router.put(
            devices.update(d.id).url,
            { paused: !d.paused },
            { preserveScroll: true },
        );

    return (
        <>
            <ConfirmDialog
                open={tokenDevice !== null}
                onOpenChange={(open) => !open && setTokenDevice(null)}
                title={`Create a new token for ${tokenDevice?.name ?? 'device'}?`}
                description="The old token stops working immediately. Copy the new token after it is shown."
                confirmLabel="Rotate token"
                destructive={false}
                onConfirm={() => {
                    if (!tokenDevice) {
                        return;
                    }
                    router.post(
                        devices.token(tokenDevice.id).url,
                        {},
                        { preserveScroll: true },
                    );
                    setTokenDevice(null);
                }}
            />
            <ConfirmDialog
                open={deleteDevice !== null}
                onOpenChange={(open) => !open && setDeleteDevice(null)}
                title={`Delete device ${deleteDevice?.name ?? ''}?`}
                description="Uptime history for this device will also be removed."
                confirmLabel="Delete device"
                onConfirm={() => {
                    if (!deleteDevice) {
                        return;
                    }
                    router.delete(devices.destroy(deleteDevice.id).url, {
                        preserveScroll: true,
                    });
                    setDeleteDevice(null);
                }}
            />
        <Table>
            <thead>
                <tr>
                    <Th>Device</Th>
                    <Th>Status</Th>
                    <Th>Last 24 hours</Th>
                    <Th className="text-right">24h</Th>
                    <Th className="text-right">7d</Th>
                    <Th className="text-right">30d</Th>
                    <Th className="text-right">Latency</Th>
                    <Th />
                </tr>
            </thead>
            <tbody>
                {list.map((d) => (
                    <tr key={d.id} data-test="device-row">
                        <Td>
                            <p className="font-medium">{d.name}</p>
                            <p className="text-xs text-muted-foreground">
                                {KIND_LABELS[d.kind]} · {target(d)}
                                {d.kitchen && ` · ${d.kitchen.name}`}
                            </p>
                            {d.meta && Object.keys(d.meta).length > 0 && (
                                <p className="text-xs text-muted-foreground">
                                    {[
                                        d.meta.cpu_percent !== undefined &&
                                            `CPU ${d.meta.cpu_percent}%`,
                                        d.meta.memory_percent !== undefined &&
                                            `RAM ${d.meta.memory_percent}%`,
                                        d.meta.disk_percent !== undefined &&
                                            `Disk ${d.meta.disk_percent}%`,
                                        d.meta.temperature_c !== undefined &&
                                            `${d.meta.temperature_c}°C`,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </p>
                            )}
                        </Td>
                        <Td>
                            <StatusDot device={d} />
                            <p className="mt-1 text-xs text-muted-foreground">
                                {d.status === 'down'
                                    ? `${deviceErrorLabel(d.last_error)} · ${since(d.status_changed_at)}`
                                    : d.status === 'up'
                                      ? `since ${since(d.status_changed_at)}`
                                      : d.consecutive_failures > 0
                                        ? `failed ${d.consecutive_failures}/${d.failure_threshold}`
                                        : ''}
                            </p>
                        </Td>
                        <Td>
                            <UptimeBars device={d} />
                        </Td>
                        <Td className="text-right tabular-nums">
                            {formatUptime(d.uptime_24h)}
                        </Td>
                        <Td className="text-right tabular-nums">
                            {formatUptime(d.uptime_7d)}
                        </Td>
                        <Td className="text-right tabular-nums">
                            {formatUptime(d.uptime_30d)}
                        </Td>
                        <Td className="text-right tabular-nums">
                            {d.avg_latency_ms === null
                                ? '-'
                                : `${d.avg_latency_ms} ms`}
                        </Td>
                        <Td className="text-right whitespace-nowrap">
                            {canManage && (
                                <>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        title={d.paused ? 'Resume' : 'Pause'}
                                        onClick={() => togglePause(d)}
                                    >
                                        {d.paused ? <Play /> : <Pause />}
                                    </Button>
                                    {d.check_type === 'heartbeat' && (
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            title="Create new token"
                                            onClick={() => setTokenDevice(d)}
                                        >
                                            <KeyRound />
                                        </Button>
                                    )}
                                    {d.check_type !== 'system' && (
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            title="Delete"
                                            onClick={() => setDeleteDevice(d)}
                                        >
                                            <Trash2 />
                                        </Button>
                                    )}
                                </>
                            )}
                        </Td>
                    </tr>
                ))}
            </tbody>
        </Table>
        </>
    );
}

function TokenPanel({
    token,
    onClose,
}: {
    token: DeviceToken;
    onClose: () => void;
}) {
    const script = `while true; do
  curl -fsS -X POST ${token.url} \\
    -H "Authorization: Bearer ${token.token}" \\
    -H "Accept: application/json" \\
    -d uptime_seconds=$(cut -d. -f1 /proc/uptime) >/dev/null
  sleep ${token.interval_seconds}
done`;

    return (
        <div
            className="relative rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm dark:border-amber-900 dark:bg-amber-950/40"
            data-test="device-token"
        >
            <Button
                variant="ghost"
                size="icon"
                className="absolute top-2 right-2"
                title="Close"
                onClick={onClose}
            >
                <X />
            </Button>
            <p className="font-medium">Heartbeat token for {token.device}</p>
            <p className="mt-1 text-muted-foreground">
                Save it now; the token is shown only once. Run this script
                on the edge box (Linux) to send a heartbeat every{' '}
                {token.interval_seconds} seconds.
            </p>
            <code className="mt-3 block rounded-md bg-background p-2 break-all">
                {token.token}
            </code>
            <pre className="mt-2 overflow-x-auto rounded-md bg-background p-3 text-xs">
                {script}
            </pre>
        </div>
    );
}

export default function Uptime({
    devices: list,
    summary,
    incidents,
    kitchens,
    sources,
}: {
    devices: Device[];
    summary: Summary;
    incidents: DeviceIncident[];
    kitchens: Kitchen[];
    sources: { id: string; name: string; kitchen_id: string }[];
}) {
    const { can, tenant } = useTenant();
    const canManage = can('manage_sources');
    const [token, setToken] = useState<DeviceToken | null>(null);

    usePoll(30000, { only: ['devices', 'summary', 'incidents'] });

    useEffect(
        () =>
            router.on('flash', (event) => {
                const data = (event as CustomEvent).detail?.flash
                    ?.device_token as DeviceToken | undefined;

                if (data) {
                    setToken(data);
                }
            }),
        [],
    );

    const form = useForm({
        name: '',
        kind: 'camera',
        kitchen_id: '',
        source_id: '',
        host: '',
        port: '554',
        interval_seconds: '60',
    });
    const isEdge = form.data.kind === 'edge';
    const kitchenSources = sources.filter(
        (s) => !form.data.kitchen_id || s.kitchen_id === form.data.kitchen_id,
    );

    const physical = list.filter((d) => d.check_type !== 'system');
    const server = list.filter((d) => d.check_type === 'system');

    return (
        <>
            <Head title="Device Uptime" />
            <Page>
                <PageHeader
                    title="Device Uptime"
                    description="Cameras, NVRs, edge boxes, network devices, and server components are checked automatically. A device is offline after several consecutive failures, then appears as a system anomaly and notification."
                />
                <PageErrors keys={['device']} />

                {token && (
                    <TokenPanel token={token} onClose={() => setToken(null)} />
                )}

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Stat
                        label="Online"
                        value={
                            <span className="text-emerald-600 dark:text-emerald-400">
                                {summary.up}
                            </span>
                        }
                        hint={`of ${summary.total} devices`}
                    />
                    <Stat
                        label="Offline"
                        value={
                            <span
                                className={
                                    summary.down > 0
                                        ? 'text-red-600 dark:text-red-400'
                                        : undefined
                                }
                            >
                                {summary.down}
                            </span>
                        }
                        hint={summary.down > 0 ? 'needs attention' : 'all normal'}
                    />
                    <Stat
                        label="Uptime (24h)"
                        value={formatUptime(summary.uptime_24h)}
                        hint="average across all checks"
                    />
                    <Stat
                        label="Paused / not checked"
                        value={`${summary.paused} / ${summary.unknown}`}
                        hint="next check runs automatically every minute"
                    />
                </div>

                {canManage && (
                    <form
                        className="space-y-4 rounded-xl border bg-card p-4 shadow-xs"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(devices.store().url, {
                                preserveScroll: true,
                                onSuccess: () => form.reset('name', 'host'),
                            });
                        }}
                    >
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 items-start">
                            <Field
                                label="Device name"
                                htmlFor="device-name"
                                error={form.errors.name}
                            >
                                <Input
                                    id="device-name"
                                    placeholder="Main Kitchen Camera"
                                    value={form.data.name}
                                    onChange={(e) =>
                                        form.setData('name', e.target.value)
                                    }
                                />
                            </Field>
                            <Field
                                label="Type"
                                htmlFor="device-kind"
                                error={form.errors.kind}
                            >
                                <NativeSelect
                                    id="device-kind"
                                    value={form.data.kind}
                                    onChange={(e) =>
                                        form.setData((data) => ({
                                            ...data,
                                            kind: e.target.value,
                                            port: DEFAULT_PORT[e.target.value],
                                        }))
                                    }
                                >
                                    {['camera', 'nvr', 'edge', 'network'].map(
                                        (k) => (
                                            <option key={k} value={k}>
                                                {KIND_LABELS[k]}
                                            </option>
                                        ),
                                    )}
                                </NativeSelect>
                            </Field>
                            <Field
                                label="Kitchen"
                                htmlFor="device-kitchen"
                                error={form.errors.kitchen_id}
                            >
                                <NativeSelect
                                    id="device-kitchen"
                                    value={form.data.kitchen_id}
                                    onChange={(e) =>
                                        form.setData((data) => ({
                                            ...data,
                                            kitchen_id: e.target.value,
                                            source_id: '',
                                        }))
                                    }
                                >
                                    <option value="">No kitchen</option>
                                    {kitchens.map((k) => (
                                        <option key={k.id} value={k.id}>
                                            {k.property?.name} · {k.name}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </Field>
                            <Field
                                label="Video source"
                                htmlFor="device-source"
                                error={form.errors.source_id}
                                hint={
                                    form.data.kind !== 'camera'
                                        ? 'Only for CCTV cameras'
                                        : undefined
                                }
                            >
                                <NativeSelect
                                    id="device-source"
                                    value={form.data.source_id}
                                    disabled={form.data.kind !== 'camera'}
                                    onChange={(e) =>
                                        form.setData(
                                            'source_id',
                                            e.target.value,
                                        )
                                    }
                                >
                                    <option value="">Not linked</option>
                                    {kitchenSources.map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.name}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </Field>
                            <Field
                                label="IP address / host"
                                htmlFor="device-host"
                                error={form.errors.host}
                            >
                                <Input
                                    id="device-host"
                                    placeholder={
                                        isEdge
                                            ? 'Not needed — use heartbeat'
                                            : '192.168.1.64'
                                    }
                                    disabled={isEdge}
                                    value={form.data.host}
                                    onChange={(e) =>
                                        form.setData('host', e.target.value)
                                    }
                                />
                            </Field>
                            <Field
                                label="Port"
                                htmlFor="device-port"
                                error={form.errors.port}
                            >
                                <Input
                                    id="device-port"
                                    inputMode="numeric"
                                    disabled={isEdge}
                                    value={form.data.port}
                                    onChange={(e) =>
                                        form.setData('port', e.target.value)
                                    }
                                />
                            </Field>
                            <Field
                                label="Check interval"
                                htmlFor="device-interval"
                                error={form.errors.interval_seconds}
                            >
                                <NativeSelect
                                    id="device-interval"
                                    value={form.data.interval_seconds}
                                    onChange={(e) =>
                                        form.setData(
                                            'interval_seconds',
                                            e.target.value,
                                        )
                                    }
                                >
                                    <option value="60">1 minute</option>
                                    <option value="300">5 minutes</option>
                                    <option value="900">15 minutes</option>
                                </NativeSelect>
                            </Field>
                        </div>
                        <div className="flex justify-end border-t pt-4">
                            <Button
                                className="h-9 min-w-24"
                                disabled={form.processing}
                                data-test="add-device"
                            >
                                Add device
                            </Button>
                        </div>
                    </form>
                )}

                <section className="grid gap-3">
                    <h2 className="text-base font-semibold">Devices</h2>
                    {physical.length === 0 ? (
                        <EmptyState
                            title="No devices yet"
                            description="Add an IP camera, NVR, router, or edge box to start monitoring uptime."
                        />
                    ) : (
                        <DeviceTable list={physical} canManage={canManage} />
                    )}
                </section>

                <section className="grid gap-3">
                    <h2 className="text-base font-semibold">
                        Server &amp; services
                    </h2>
                    <DeviceTable list={server} canManage={canManage} />
                </section>

                <section className="grid gap-3">
                    <h2 className="text-base font-semibold">
                        Outage history
                    </h2>
                    {incidents.length === 0 ? (
                        <EmptyState title="No outages recorded yet" />
                    ) : (
                        <Table>
                            <thead>
                                <tr>
                                    <Th>Device</Th>
                                    <Th>Started</Th>
                                    <Th>Ended</Th>
                                    <Th>Duration</Th>
                                    <Th>Reason</Th>
                                    <Th />
                                </tr>
                            </thead>
                            <tbody>
                                {incidents.map((i) => (
                                    <tr key={i.id} data-test="incident-row">
                                        <Td className="font-medium">
                                            {i.device?.name}
                                        </Td>
                                        <Td>
                                            {formatDateTime(
                                                i.started_at,
                                                tenant?.timezone,
                                            )}
                                        </Td>
                                        <Td>
                                            {i.ended_at ? (
                                                formatDateTime(
                                                    i.ended_at,
                                                    tenant?.timezone,
                                                )
                                            ) : (
                                                <Pill tone="danger">
                                                    Still offline
                                                </Pill>
                                            )}
                                        </Td>
                                        <Td className="tabular-nums">
                                            {formatDuration(
                                                (i.ended_at
                                                    ? new Date(
                                                          i.ended_at,
                                                      ).getTime()
                                                    : Date.now()) -
                                                    new Date(
                                                        i.started_at,
                                                    ).getTime(),
                                            )}
                                        </Td>
                                        <Td>{deviceErrorLabel(i.reason)}</Td>
                                        <Td className="text-right">
                                            {i.event_id && (
                                                <Link
                                                    href={events.show(
                                                        i.event_id,
                                                    )}
                                                    className="text-sm hover:underline"
                                                >
                                                    View anomaly
                                                </Link>
                                            )}
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    )}
                </section>
            </Page>
        </>
    );
}

Uptime.layout = {
    breadcrumbs: [{ title: 'Device Uptime', href: uptime.index() }],
};
