import { Head, Link, router, useForm, usePoll } from '@inertiajs/react';
import { KeyRound, Pause, Play, Trash2, X } from 'lucide-react';
import { useEffect, useState } from 'react';
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
    camera: 'Kamera CCTV',
    nvr: 'NVR / DVR',
    edge: 'Edge box (heartbeat)',
    network: 'Perangkat jaringan',
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
        return '< 1 mnt';
    }

    const h = Math.floor(minutes / 60);
    const d = Math.floor(h / 24);

    if (d > 0) {
        return `${d} hr ${h % 24} jam`;
    }

    return h > 0 ? `${h} jam ${minutes % 60} mnt` : `${minutes} mnt`;
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
        return <Pill tone="neutral">Dijeda</Pill>;
    }

    return device.status === 'up' ? (
        <Pill tone="success">Online</Pill>
    ) : device.status === 'down' ? (
        <Pill tone="danger">Offline</Pill>
    ) : (
        <Pill tone="neutral">Belum dicek</Pill>
    );
}

function UptimeBars({ device }: { device: Device }) {
    const { tenant } = useTenant();

    return (
        <div
            className="flex h-6 items-end gap-px"
            data-test="uptime-bars"
            aria-label={`Uptime 24 jam ${formatUptime(device.uptime_24h)}`}
        >
            {device.bars.map((b) => (
                <span
                    key={b.hour}
                    title={`${formatDateTime(b.hour, tenant?.timezone)} · ${b.uptime === null ? 'tidak ada data' : formatUptime(b.uptime)}`}
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
            ? `heartbeat ${since(device.last_seen_at)} lalu`
            : 'menunggu heartbeat pertama';
    }

    if (device.check_type === 'system') {
        return 'komponen server';
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
    const togglePause = (d: Device) =>
        router.put(
            devices.update(d.id).url,
            { paused: !d.paused },
            { preserveScroll: true },
        );

    const rotate = (d: Device) => {
        if (
            confirm(
                `Buat token baru untuk ${d.name}? Token lama langsung tidak berlaku.`,
            )
        ) {
            router.post(devices.token(d.id).url, {}, { preserveScroll: true });
        }
    };

    const remove = (d: Device) => {
        if (confirm(`Hapus perangkat ${d.name} beserta riwayat uptime-nya?`)) {
            router.delete(devices.destroy(d.id).url, { preserveScroll: true });
        }
    };

    return (
        <Table>
            <thead>
                <tr>
                    <Th>Perangkat</Th>
                    <Th>Status</Th>
                    <Th>24 jam terakhir</Th>
                    <Th className="text-right">24 jam</Th>
                    <Th className="text-right">7 hari</Th>
                    <Th className="text-right">30 hari</Th>
                    <Th className="text-right">Latensi</Th>
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
                                      ? `sejak ${since(d.status_changed_at)}`
                                      : d.consecutive_failures > 0
                                        ? `gagal ${d.consecutive_failures}/${d.failure_threshold}`
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
                                        title={d.paused ? 'Lanjutkan' : 'Jeda'}
                                        onClick={() => togglePause(d)}
                                    >
                                        {d.paused ? <Play /> : <Pause />}
                                    </Button>
                                    {d.check_type === 'heartbeat' && (
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            title="Buat token baru"
                                            onClick={() => rotate(d)}
                                        >
                                            <KeyRound />
                                        </Button>
                                    )}
                                    {d.check_type !== 'system' && (
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            title="Hapus"
                                            onClick={() => remove(d)}
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
                title="Tutup"
                onClick={onClose}
            >
                <X />
            </Button>
            <p className="font-medium">Token heartbeat untuk {token.device}</p>
            <p className="mt-1 text-muted-foreground">
                Simpan sekarang; token hanya ditampilkan sekali. Jalankan skrip
                ini di edge box (Linux) agar mengirim heartbeat setiap{' '}
                {token.interval_seconds} detik.
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
            <Head title="Uptime Perangkat" />
            <Page>
                <PageHeader
                    title="Uptime Perangkat"
                    description="Kamera, NVR, edge box, perangkat jaringan dan komponen server dicek otomatis. Perangkat dianggap offline setelah gagal beberapa kali berturut-turut, lalu muncul sebagai anomali sistem dan notifikasi."
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
                        hint={`dari ${summary.total} perangkat`}
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
                        hint={summary.down > 0 ? 'perlu dicek' : 'semua normal'}
                    />
                    <Stat
                        label="Uptime 24 jam"
                        value={formatUptime(summary.uptime_24h)}
                        hint="rata-rata semua pengecekan"
                    />
                    <Stat
                        label="Dijeda / belum dicek"
                        value={`${summary.paused} / ${summary.unknown}`}
                        hint="cek berikutnya otomatis tiap menit"
                    />
                </div>

                {canManage && (
                    <form
                        className="grid gap-3 rounded-xl border p-4 md:grid-cols-3 lg:grid-cols-[1.2fr_1fr_1fr_1fr_1.2fr_90px_110px_auto] lg:items-end"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(devices.store().url, {
                                preserveScroll: true,
                                onSuccess: () => form.reset('name', 'host'),
                            });
                        }}
                    >
                        <Field
                            label="Nama perangkat"
                            htmlFor="device-name"
                            error={form.errors.name}
                        >
                            <Input
                                id="device-name"
                                placeholder="Kamera Dapur Utama"
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Jenis"
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
                            label="Dapur"
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
                                <option value="">Tanpa dapur</option>
                                {kitchens.map((k) => (
                                    <option key={k.id} value={k.id}>
                                        {k.property?.name} · {k.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        <Field
                            label="Sumber video"
                            htmlFor="device-source"
                            error={form.errors.source_id}
                        >
                            <NativeSelect
                                id="device-source"
                                value={form.data.source_id}
                                disabled={form.data.kind !== 'camera'}
                                onChange={(e) =>
                                    form.setData('source_id', e.target.value)
                                }
                            >
                                <option value="">Tidak ditautkan</option>
                                {kitchenSources.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        <Field
                            label="Alamat IP / host"
                            htmlFor="device-host"
                            error={form.errors.host}
                        >
                            <Input
                                id="device-host"
                                placeholder={
                                    isEdge
                                        ? 'tidak perlu, pakai heartbeat'
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
                            label="Interval"
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
                                <option value="60">1 menit</option>
                                <option value="300">5 menit</option>
                                <option value="900">15 menit</option>
                            </NativeSelect>
                        </Field>
                        <Button
                            disabled={form.processing}
                            data-test="add-device"
                        >
                            Tambah
                        </Button>
                    </form>
                )}

                <section className="grid gap-3">
                    <h2 className="text-base font-semibold">Perangkat</h2>
                    {physical.length === 0 ? (
                        <EmptyState
                            title="Belum ada perangkat"
                            description="Tambahkan kamera IP, NVR, router atau edge box untuk mulai memantau uptime."
                        />
                    ) : (
                        <DeviceTable list={physical} canManage={canManage} />
                    )}
                </section>

                <section className="grid gap-3">
                    <h2 className="text-base font-semibold">
                        Server &amp; layanan
                    </h2>
                    <DeviceTable list={server} canManage={canManage} />
                </section>

                <section className="grid gap-3">
                    <h2 className="text-base font-semibold">
                        Riwayat gangguan
                    </h2>
                    {incidents.length === 0 ? (
                        <EmptyState title="Belum ada gangguan tercatat" />
                    ) : (
                        <Table>
                            <thead>
                                <tr>
                                    <Th>Perangkat</Th>
                                    <Th>Mulai</Th>
                                    <Th>Selesai</Th>
                                    <Th>Durasi</Th>
                                    <Th>Penyebab</Th>
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
                                                    Masih offline
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
                                                    Lihat anomali
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
    breadcrumbs: [{ title: 'Uptime Perangkat', href: uptime.index() }],
};
