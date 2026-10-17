import { Deferred, Head, Link } from '@inertiajs/react';
import { HardDrive, Info, MonitorPlay } from 'lucide-react';
import {
    CategoryBadge,
    EmptyState,
    Page,
    PageHeader,
    Pill,
    ReviewBadge,
    RunStatusBadge,
    SimulationBadge,
    Stat,
    Table,
    Td,
    Th,
} from '@/components/kitchen/ui';
import { Button } from '@/components/ui/button';
import {
    eventPlace,
    eventTypeLabel,
    formatDateTime,
    formatMs,
    formatPercent,
    useTenant,
} from '@/lib/kitchen';
import { dashboard, monitor } from '@/routes';
import analyses from '@/routes/analyses';
import events from '@/routes/events';
import system from '@/routes/system';
import uptime from '@/routes/uptime';
import videos from '@/routes/videos';
import type { AnomalyEvent, Run } from '@/types/kitchen';

type Room = {
    id: string;
    name: string;
    property: string | null;
    cameras: number;
    online: number;
    people: number;
    work_zone_ms: number;
    open_events: number;
    last_analysis_at: string | null;
};

type Health = {
    database: boolean;
    redis: boolean;
    worker: boolean;
    analysis_ready: boolean;
    storage_used_percent: number;
    storage_warning: boolean;
    storage_blocked: boolean;
    active_sources: number;
    queued_runs: number;
    running_runs: number;
    devices: { total: number; up: number; down: number };
};

type Props = {
    stats: {
        needs_review: number;
        needs_review_simulation: number;
        people: number;
        work_zone_ms: number;
        restricted: number;
        analysed_videos: number;
        ppe_compliance: number | null;
        ppe_coverage: number | null;
    };
    trend: { day: string; violations: number; reviews: number }[];
    rooms: Room[];
    recentEvents: AnomalyEvent[];
    activeRuns: (Pick<
        Run,
        'id' | 'status' | 'mode' | 'progress' | 'phase' | 'created_at'
    > & {
        video?: { id: string; original_name: string } | null;
    })[];
    storage: { used_percent: number; warning: boolean; blocked: boolean };
    health?: Health;
};

export default function Dashboard({
    stats,
    trend,
    rooms,
    recentEvents,
    activeRuns,
    storage,
    health,
}: Props) {
    const { tenant, can } = useTenant();
    const max = Math.max(1, ...trend.map((t) => t.violations + t.reviews));
    const ppeMeasured = (stats.ppe_coverage ?? 0) > 0;
    const scope = `${stats.analysed_videos} video teranalisis, 7 hari`;

    return (
        <>
            <Head title="Dashboard" />
            <Page>
                <PageHeader
                    title="Dashboard"
                    description={`Ringkasan ${tenant?.name ?? ''}. Angka utama hanya dari analisis nyata terbaru per video; hasil simulasi dipisahkan.`}
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                <Link href={monitor()}>
                                    <MonitorPlay />
                                    Monitor CCTV
                                </Link>
                            </Button>
                            {can('upload_video') && (
                                <Button asChild>
                                    <Link href={videos.index()}>
                                        Upload video
                                    </Link>
                                </Button>
                            )}
                        </>
                    }
                />

                {(storage.warning || storage.blocked) && (
                    <div
                        className={`flex items-center gap-2 rounded-lg border px-4 py-3 text-sm ${storage.blocked ? 'border-destructive/40 bg-destructive/5 text-destructive' : 'border-amber-300 bg-amber-50 text-amber-900 dark:bg-amber-950 dark:text-amber-100'}`}
                    >
                        <HardDrive className="size-4" />
                        Penyimpanan terpakai {storage.used_percent.toFixed(1)}%.
                        {storage.blocked
                            ? ' Upload dan analisis baru diblokir sampai ruang dikosongkan.'
                            : ' Segera kosongkan ruang penyimpanan.'}
                    </div>
                )}

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Stat
                        label="Perlu ditinjau"
                        value={stats.needs_review}
                        hint={
                            stats.needs_review_simulation > 0
                                ? `+${stats.needs_review_simulation} dari simulasi`
                                : 'Anomali nyata yang belum diputuskan'
                        }
                    />
                    <Stat
                        label="Orang terdeteksi"
                        value={stats.people}
                        hint={`Jejak orang dari ${scope}`}
                    />
                    <Stat
                        label="Waktu di zona kerja"
                        value={formatMs(stats.work_zone_ms)}
                        hint="Total durasi orang di zona persiapan, kompor, cuci, dan kebersihan"
                    />
                    <Stat
                        label="Masuk area terlarang"
                        value={stats.restricted}
                        hint="Kejadian nyata, 7 hari terakhir"
                    />
                </div>

                <p
                    className="flex items-start gap-2 rounded-lg border px-4 py-3 text-sm text-muted-foreground"
                    data-test="ppe-note"
                >
                    <Info className="mt-0.5 size-4 shrink-0" />
                    {ppeMeasured ? (
                        <span>
                            Kepatuhan APD 7 hari:{' '}
                            <strong className="text-foreground">
                                {formatPercent(stats.ppe_compliance)}
                            </strong>{' '}
                            dengan cakupan penilaian{' '}
                            {formatPercent(stats.ppe_coverage)}. Waktu yang
                            tidak dapat dinilai tidak dihitung patuh.
                        </span>
                    ) : (
                        <span>
                            Model AI yang dipakai saat ini hanya mendeteksi
                            orang, belum APD (penutup rambut, celemek). Karena
                            itu kepatuhan APD belum dihitung dan tidak dijadikan
                            anomali; dashboard berfokus pada keberadaan orang,
                            zona kerja, dan area terlarang.
                        </span>
                    )}
                </p>

                <div className="grid gap-4 lg:grid-cols-[2fr_1fr]">
                    <section className="space-y-3">
                        <div className="flex items-center justify-between">
                            <h2 className="font-medium">
                                Ringkasan per ruangan
                            </h2>
                            <Link
                                href={monitor()}
                                className="text-sm text-muted-foreground hover:underline"
                            >
                                Buka monitor
                            </Link>
                        </div>
                        {rooms.length === 0 ? (
                            <EmptyState
                                title="Belum ada ruangan"
                                description="Tambahkan outlet dan dapur di menu Lokasi, lalu hubungkan kamera di Sumber Video."
                            />
                        ) : (
                            <Table>
                                <thead>
                                    <tr>
                                        <Th>Ruangan</Th>
                                        <Th>Kamera</Th>
                                        <Th>Orang</Th>
                                        <Th>Di zona kerja</Th>
                                        <Th>Anomali terbuka</Th>
                                        <Th>Analisis terakhir</Th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rooms.map((room) => (
                                        <tr
                                            key={room.id}
                                            className="hover:bg-muted/50"
                                            data-test="room-row"
                                        >
                                            <Td>
                                                <Link
                                                    href={monitor({
                                                        query: {
                                                            ruangan: room.id,
                                                        },
                                                    })}
                                                    className="font-medium hover:underline"
                                                >
                                                    {room.name}
                                                </Link>
                                                <p className="text-xs text-muted-foreground">
                                                    {room.property}
                                                </p>
                                            </Td>
                                            <Td className="whitespace-nowrap tabular-nums">
                                                <span
                                                    className={
                                                        room.online <
                                                        room.cameras
                                                            ? 'text-amber-600 dark:text-amber-400'
                                                            : undefined
                                                    }
                                                >
                                                    {room.online}/{room.cameras}
                                                </span>{' '}
                                                <span className="text-xs text-muted-foreground">
                                                    online
                                                </span>
                                            </Td>
                                            <Td className="tabular-nums">
                                                {room.people}
                                            </Td>
                                            <Td className="font-mono tabular-nums">
                                                {formatMs(room.work_zone_ms)}
                                            </Td>
                                            <Td>
                                                {room.open_events > 0 ? (
                                                    <Pill tone="warning">
                                                        {room.open_events}
                                                    </Pill>
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        0
                                                    </span>
                                                )}
                                            </Td>
                                            <Td className="text-xs whitespace-nowrap text-muted-foreground">
                                                {room.last_analysis_at
                                                    ? formatDateTime(
                                                          room.last_analysis_at,
                                                          tenant?.timezone,
                                                      )
                                                    : 'Belum dianalisis'}
                                            </Td>
                                        </tr>
                                    ))}
                                </tbody>
                            </Table>
                        )}
                    </section>

                    <section className="space-y-3">
                        <div className="flex items-center justify-between">
                            <h2 className="font-medium">Kesehatan sistem</h2>
                            {can('manage_organization') && (
                                <Link
                                    href={system.index()}
                                    className="text-sm text-muted-foreground hover:underline"
                                >
                                    Detail
                                </Link>
                            )}
                        </div>
                        <Deferred
                            data="health"
                            fallback={
                                <div className="rounded-xl border p-4 text-sm text-muted-foreground">
                                    Memeriksa layanan...
                                </div>
                            }
                        >
                            <HealthPanel health={health} />
                        </Deferred>
                    </section>
                </div>

                <div className="grid gap-4 lg:grid-cols-[1fr_1fr]">
                    <section className="rounded-xl border p-4">
                        <h2 className="mb-1 font-medium">
                            Anomali nyata per hari
                        </h2>
                        <p className="mb-4 flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                            <span>7 hari terakhir ({tenant?.timezone}).</span>
                            <span className="flex items-center gap-1">
                                <span className="size-2 rounded-sm bg-red-500" />
                                Pelanggaran
                            </span>
                            <span className="flex items-center gap-1">
                                <span className="size-2 rounded-sm bg-blue-500" />
                                Perlu ditinjau
                            </span>
                        </p>
                        <div className="flex h-40 items-end gap-2">
                            {trend.map((t) => (
                                <div
                                    key={t.day}
                                    className="flex h-full flex-1 flex-col items-center justify-end gap-1"
                                >
                                    <span className="text-xs tabular-nums">
                                        {t.violations + t.reviews}
                                    </span>
                                    <div
                                        className="flex w-full flex-col justify-end overflow-hidden rounded-t bg-muted"
                                        style={{
                                            height: `${((t.violations + t.reviews) / max) * 100}%`,
                                            minHeight: 2,
                                        }}
                                    >
                                        <div
                                            className="bg-blue-500"
                                            style={{
                                                flexGrow: t.reviews,
                                            }}
                                        />
                                        <div
                                            className="bg-red-500"
                                            style={{
                                                flexGrow: t.violations,
                                            }}
                                        />
                                    </div>
                                    <span className="text-[11px] text-muted-foreground">
                                        {t.day.slice(5)}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </section>

                    <section className="rounded-xl border p-4">
                        <h2 className="mb-3 font-medium">Analisis aktif</h2>
                        {activeRuns.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Tidak ada analisis yang berjalan.
                            </p>
                        ) : (
                            <ul className="space-y-3">
                                {activeRuns.map((run) => (
                                    <li key={run.id}>
                                        <Link
                                            href={analyses.show(run.id)}
                                            className="flex items-center gap-2 text-sm hover:underline"
                                        >
                                            <RunStatusBadge
                                                status={run.status}
                                            />
                                            {run.mode === 'simulation' && (
                                                <SimulationBadge />
                                            )}
                                            <span className="flex-1 truncate">
                                                {run.video?.original_name}
                                            </span>
                                            <span className="text-muted-foreground tabular-nums">
                                                {run.progress}%
                                            </span>
                                        </Link>
                                        <div className="mt-1 h-1.5 rounded bg-muted">
                                            <div
                                                className="h-full rounded bg-primary"
                                                style={{
                                                    width: `${run.progress}%`,
                                                }}
                                            />
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>

                <section className="space-y-3">
                    <div className="flex items-center justify-between">
                        <h2 className="font-medium">Menunggu review</h2>
                        <Link
                            href={events.index()}
                            className="text-sm text-muted-foreground hover:underline"
                        >
                            Lihat semua
                        </Link>
                    </div>
                    {recentEvents.length === 0 ? (
                        <EmptyState
                            title="Tidak ada anomali yang menunggu review"
                            description="Kandidat pelanggaran baru dari analisis akan muncul di sini sampai diputuskan."
                        />
                    ) : (
                        <div className="divide-y rounded-xl border">
                            {recentEvents.map((e) => (
                                <Link
                                    key={e.id}
                                    href={events.show(e.id)}
                                    className="flex flex-wrap items-center gap-2 px-4 py-3 text-sm hover:bg-muted/50"
                                >
                                    <span className="font-medium">
                                        {eventTypeLabel(e.type)}
                                    </span>
                                    <CategoryBadge category={e.category} />
                                    {e.is_simulation && <SimulationBadge />}
                                    <span className="text-muted-foreground">
                                        {eventPlace(e)}
                                        {e.first_ms !== null &&
                                            ` · ${formatMs(e.first_ms)}`}
                                    </span>
                                    <span className="ml-auto flex items-center gap-2">
                                        <ReviewBadge status={e.review_status} />
                                        <span className="text-xs text-muted-foreground">
                                            {formatDateTime(
                                                e.created_at,
                                                tenant?.timezone,
                                            )}
                                        </span>
                                    </span>
                                </Link>
                            ))}
                        </div>
                    )}
                </section>
            </Page>
        </>
    );
}

function HealthPanel({ health }: { health?: Health }) {
    if (!health) {
        return null;
    }

    const storageTone = health.storage_blocked
        ? 'danger'
        : health.storage_warning
          ? 'warning'
          : 'success';
    const rows: { label: string; ok: boolean; detail?: string }[] = [
        { label: 'Database', ok: health.database },
        { label: 'Antrean (Redis)', ok: health.redis },
        {
            label: 'Worker AI',
            ok: health.worker,
            detail: `${health.running_runs} berjalan · ${health.queued_runs} antre`,
        },
        {
            label: 'Kamera aktif',
            ok: health.active_sources > 0,
            detail: String(health.active_sources),
        },
    ];

    return (
        <div className="divide-y rounded-xl border text-sm" data-test="health">
            {rows.map((row) => (
                <div
                    key={row.label}
                    className="flex items-center justify-between gap-2 px-4 py-2.5"
                >
                    <span>{row.label}</span>
                    <span className="flex items-center gap-2">
                        {row.detail && (
                            <span className="text-xs text-muted-foreground">
                                {row.detail}
                            </span>
                        )}
                        <Pill tone={row.ok ? 'success' : 'danger'}>
                            {row.ok ? 'OK' : 'Bermasalah'}
                        </Pill>
                    </span>
                </div>
            ))}
            <Link
                href={uptime.index()}
                className="flex items-center justify-between gap-2 px-4 py-2.5 hover:bg-muted/50"
                data-test="health-devices"
            >
                <span>Perangkat online</span>
                <span className="flex items-center gap-2">
                    {health.devices.total > 0 && (
                        <span className="text-xs text-muted-foreground">
                            {health.devices.up}/{health.devices.total}
                        </span>
                    )}
                    {health.devices.total === 0 ? (
                        <Pill tone="neutral">Belum diatur</Pill>
                    ) : (
                        <Pill
                            tone={
                                health.devices.down > 0 ? 'danger' : 'success'
                            }
                        >
                            {health.devices.down > 0
                                ? `${health.devices.down} offline`
                                : 'OK'}
                        </Pill>
                    )}
                </span>
            </Link>
            <div className="space-y-2 px-4 py-2.5">
                <div className="flex items-center justify-between gap-2">
                    <span>Penyimpanan</span>
                    <Pill tone={storageTone}>
                        {health.storage_blocked
                            ? 'Penuh'
                            : health.storage_warning
                              ? 'Hampir penuh'
                              : 'OK'}
                    </Pill>
                </div>
                <div className="h-1.5 rounded bg-muted">
                    <div
                        className={`h-full rounded ${storageTone === 'danger' ? 'bg-red-500' : storageTone === 'warning' ? 'bg-amber-500' : 'bg-emerald-500'}`}
                        style={{
                            width: `${Math.min(100, health.storage_used_percent)}%`,
                        }}
                    />
                </div>
                <p className="text-xs text-muted-foreground">
                    {health.storage_used_percent.toFixed(1)}% terpakai
                </p>
            </div>
            {!health.analysis_ready && (
                <p className="px-4 py-2.5 text-xs text-destructive">
                    Analisis baru belum bisa dijalankan. Periksa worker AI atau
                    status jeda analisis.
                </p>
            )}
        </div>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
