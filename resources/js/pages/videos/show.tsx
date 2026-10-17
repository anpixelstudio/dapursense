import { Head, Link, router, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useEffect } from 'react';
import { VideoPlayer } from '@/components/kitchen/video-player';
import { ZoneEditor } from '@/components/kitchen/zone-editor';
import {
    EmptyState,
    Field,
    NativeSelect,
    Page,
    PageErrors,
    PageHeader,
    RunStatusBadge,
    SimulationBadge,
    Table,
    Td,
    Th,
    VideoStatusBadge,
} from '@/components/kitchen/ui';
import { Button } from '@/components/ui/button';
import {
    formatBytes,
    formatDateTime,
    formatMs,
    getJson,
    ruleRevisionLabel,
    useTenant,
} from '@/lib/kitchen';
import analyses from '@/routes/analyses';
import rules from '@/routes/rules';
import videos from '@/routes/videos';
import type { AiModel, Run, Video, Zone } from '@/types/kitchen';

type Props = {
    video: Video & {
        source: {
            id: string;
            name: string;
            kitchen?: { name: string; property?: { name: string } };
        };
    };
    zones: Zone[];
    runs: Run[];
    models: AiModel[];
    ruleSets: {
        id: string;
        name: string;
        active_revision?: { id: string; version: number } | null;
    }[];
    otherSources: { id: string; name: string }[];
    reanalysisAvailableUntil: string | null;
    canReanalyze: boolean;
};

export default function VideoShow({
    video,
    zones,
    runs,
    models,
    ruleSets,
    otherSources,
    reanalysisAvailableUntil,
    canReanalyze,
}: Props) {
    const { can, tenant } = useTenant();
    const form = useForm({
        model_id: models[0]?.id ?? '',
        rule_set_id: ruleSets[0]?.id ?? '',
        mode: models[0]?.capabilities.simulated ? 'simulation' : 'actual',
    });

    useEffect(() => {
        if (video.status !== 'VALIDATING') {
            return;
        }
        const timer = setInterval(() => {
            getJson<{ status: string }>(videos.status(video.id).url)
                .then((s) => s.status !== 'VALIDATING' && router.reload())
                .catch(() => undefined);
        }, 2000);

        return () => clearInterval(timer);
    }, [video.id, video.status]);

    const selectedModel = models.find((m) => m.id === form.data.model_id);

    const remove = () => {
        if (confirm('Hapus video ini?')) {
            router.delete(videos.destroy(video.id).url);
        }
    };

    return (
        <>
            <Head title={video.original_name} />
            <Page>
                <PageHeader
                    title={video.original_name}
                    description={`${video.source.kitchen?.property?.name ?? ''} · ${video.source.kitchen?.name ?? ''} · ${video.source.name}`}
                    actions={
                        <>
                            <VideoStatusBadge status={video.status} />
                            {can('manage_sources') && runs.length === 0 && (
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    title="Hapus video"
                                    onClick={remove}
                                >
                                    <Trash2 />
                                </Button>
                            )}
                        </>
                    }
                />
                <PageErrors keys={['video']} />

                {video.status === 'VALIDATING' && (
                    <EmptyState
                        title="Video sedang divalidasi"
                        description="Memeriksa format, durasi, dan membuat preview H.264. Halaman akan diperbarui otomatis."
                    />
                )}
                {video.status === 'REJECTED' && (
                    <EmptyState
                        title="Video ditolak"
                        description={
                            video.error_message ??
                            video.error_code ??
                            'Video tidak dapat diproses.'
                        }
                    />
                )}

                {video.status === 'READY' && (
                    <>
                        <div className="grid gap-4 lg:grid-cols-[1fr_340px]">
                            {video.preview_key ? (
                                <VideoPlayer
                                    src={videos.preview(video.id).url}
                                    zones={zones}
                                />
                            ) : (
                                <EmptyState
                                    title="Preview sudah kedaluwarsa"
                                    description="Event dan hasil analisis tetap dapat dilihat."
                                />
                            )}
                            <div className="space-y-2 rounded-xl border p-4 text-sm">
                                <p className="font-medium">Informasi video</p>
                                <dl className="grid grid-cols-[110px_1fr] gap-y-1 text-muted-foreground">
                                    <dt>Durasi</dt>
                                    <dd className="text-foreground">
                                        {formatMs(video.duration_ms)}
                                    </dd>
                                    <dt>Resolusi</dt>
                                    <dd className="text-foreground">
                                        {video.width}×{video.height} ·{' '}
                                        {video.fps?.toFixed(1)} fps
                                    </dd>
                                    <dt>Codec</dt>
                                    <dd className="text-foreground">
                                        {video.codec}
                                    </dd>
                                    <dt>Ukuran</dt>
                                    <dd className="text-foreground">
                                        {formatBytes(video.bytes)}
                                    </dd>
                                    <dt>Direkam</dt>
                                    <dd className="text-foreground">
                                        {video.recorded_at
                                            ? formatDateTime(
                                                  video.recorded_at,
                                                  video.recorded_timezone ??
                                                      undefined,
                                              )
                                            : 'Tidak diketahui'}
                                    </dd>
                                    <dt>Analisis ulang</dt>
                                    <dd className="text-foreground">
                                        {canReanalyze
                                            ? `tersedia sampai ${formatDateTime(reanalysisAvailableUntil, tenant?.timezone)}`
                                            : 'tidak tersedia (file asli kedaluwarsa)'}
                                    </dd>
                                </dl>
                            </div>
                        </div>

                        <section className="space-y-3">
                            <h2 className="font-medium">Zona dapur</h2>
                            <ZoneEditor
                                sourceId={video.source_id}
                                imageUrl={videos.reference(video.id).url}
                                zones={zones}
                                otherSources={otherSources}
                                canEdit={can('manage_sources')}
                            />
                        </section>

                        {can('run_analysis') && (
                            <section className="space-y-3 rounded-xl border p-4">
                                <h2 className="font-medium">
                                    Jalankan analisis
                                </h2>
                                {ruleSets.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        Belum ada aturan SOP aktif.{' '}
                                        <Link
                                            className="underline"
                                            href={rules.index()}
                                        >
                                            Publikasikan aturan
                                        </Link>{' '}
                                        terlebih dahulu.
                                    </p>
                                ) : (
                                    <form
                                        className="grid gap-3 md:grid-cols-[1fr_1fr_auto] md:items-end"
                                        onSubmit={(e) => {
                                            e.preventDefault();
                                            form.post(
                                                analyses.store(video.id).url,
                                            );
                                        }}
                                    >
                                        <Field
                                            label="Model AI"
                                            htmlFor="run-model"
                                            error={form.errors.model_id}
                                            hint={
                                                selectedModel?.capabilities
                                                    .notes
                                            }
                                        >
                                            <NativeSelect
                                                id="run-model"
                                                value={form.data.model_id}
                                                onChange={(e) => {
                                                    const model = models.find(
                                                        (m) =>
                                                            m.id ===
                                                            e.target.value,
                                                    );
                                                    form.setData({
                                                        ...form.data,
                                                        model_id:
                                                            e.target.value,
                                                        mode: model
                                                            ?.capabilities
                                                            .simulated
                                                            ? 'simulation'
                                                            : 'actual',
                                                    });
                                                }}
                                            >
                                                {models.map((m) => (
                                                    <option
                                                        key={m.id}
                                                        value={m.id}
                                                    >
                                                        {m.name} {m.version}
                                                        {m.capabilities
                                                            .simulated
                                                            ? ' (simulasi)'
                                                            : ''}
                                                    </option>
                                                ))}
                                            </NativeSelect>
                                        </Field>
                                        <Field
                                            label="Aturan SOP"
                                            htmlFor="run-rules"
                                            error={form.errors.rule_set_id}
                                        >
                                            <NativeSelect
                                                id="run-rules"
                                                value={form.data.rule_set_id}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'rule_set_id',
                                                        e.target.value,
                                                    )
                                                }
                                            >
                                                {ruleSets.map((r) => (
                                                    <option
                                                        key={r.id}
                                                        value={r.id}
                                                    >
                                                        {r.name} (revisi{' '}
                                                        {
                                                            r.active_revision
                                                                ?.version
                                                        }
                                                        )
                                                    </option>
                                                ))}
                                            </NativeSelect>
                                        </Field>
                                        <Button
                                            disabled={
                                                form.processing || !canReanalyze
                                            }
                                            data-test="start-analysis"
                                        >
                                            {form.data.mode === 'simulation'
                                                ? 'Jalankan simulasi'
                                                : 'Jalankan analisis'}
                                        </Button>
                                        {form.errors.mode && (
                                            <p className="text-sm text-destructive md:col-span-3">
                                                {form.errors.mode}
                                            </p>
                                        )}
                                        {form.data.mode === 'simulation' && (
                                            <p className="text-xs text-muted-foreground md:col-span-3">
                                                Mode simulasi menghasilkan
                                                deteksi buatan untuk demo alur.
                                                Hasilnya diberi label SIMULASI
                                                dan tidak masuk angka kepatuhan.
                                            </p>
                                        )}
                                        {zones.length === 0 && (
                                            <p className="text-xs text-amber-700 md:col-span-3">
                                                Belum ada zona: APD dan area
                                                terlarang tidak akan dinilai.
                                            </p>
                                        )}
                                    </form>
                                )}
                            </section>
                        )}
                    </>
                )}

                <section className="space-y-3">
                    <h2 className="font-medium">Riwayat analisis</h2>
                    {runs.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Belum ada analisis untuk video ini.
                        </p>
                    ) : (
                        <Table>
                            <thead>
                                <tr>
                                    <Th>Status</Th>
                                    <Th>Model</Th>
                                    <Th>Aturan</Th>
                                    <Th>Progres</Th>
                                    <Th>Diminta</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {runs.map((r) => (
                                    <tr key={r.id}>
                                        <Td>
                                            <Link
                                                href={analyses.show(r.id)}
                                                className="flex items-center gap-2 hover:underline"
                                            >
                                                <RunStatusBadge
                                                    status={r.status}
                                                />
                                                {r.mode === 'simulation' && (
                                                    <SimulationBadge />
                                                )}
                                            </Link>
                                        </Td>
                                        <Td>
                                            {r.model?.name} {r.model?.version}
                                        </Td>
                                        <Td>
                                            {ruleRevisionLabel(r.rule_revision)}
                                        </Td>
                                        <Td className="tabular-nums">
                                            {r.progress}%
                                        </Td>
                                        <Td className="whitespace-nowrap text-muted-foreground">
                                            {r.requester?.name} ·{' '}
                                            {formatDateTime(
                                                r.created_at,
                                                tenant?.timezone,
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

VideoShow.layout = {
    breadcrumbs: [{ title: 'Video & Analisis', href: videos.index() }],
};
