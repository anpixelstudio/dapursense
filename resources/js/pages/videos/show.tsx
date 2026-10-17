import { Head, Link, router, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { ConfirmDialog } from '@/components/kitchen/confirm-dialog';
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
    const ppeSupported = (m?: AiModel) =>
        Object.values(m?.capabilities.ppe ?? {}).some(Boolean);
    const preferredModel =
        models.find((m) => !m.capabilities.simulated && ppeSupported(m)) ??
        models.find((m) => !m.capabilities.simulated) ??
        models[0];
    const form = useForm({
        model_id: preferredModel?.id ?? '',
        rule_set_id: ruleSets[0]?.id ?? '',
        mode: preferredModel?.capabilities.simulated ? 'simulation' : 'actual',
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
    const [confirmDelete, setConfirmDelete] = useState(false);

    return (
        <>
            <Head title={video.original_name} />
            <ConfirmDialog
                open={confirmDelete}
                onOpenChange={setConfirmDelete}
                title="Delete this video?"
                description="The file and its analysis history for this asset will be removed. This cannot be undone."
                confirmLabel="Delete video"
                onConfirm={() =>
                    router.delete(videos.destroy(video.id).url)
                }
            />
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
                                    title="Delete video"
                                    onClick={() => setConfirmDelete(true)}
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
                        title="Video is being validated"
                        description="Checking format, duration, and building an H.264 preview. This page refreshes automatically."
                    />
                )}
                {video.status === 'REJECTED' && (
                    <EmptyState
                        title="Video rejected"
                        description={
                            video.error_message ??
                            video.error_code ??
                            'Video could not be processed.'
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
                                    title="Preview expired"
                                    description="Events and analysis results remain viewable."
                                />
                            )}
                            <div className="space-y-2 rounded-xl border p-4 text-sm">
                                <p className="font-medium">Video information</p>
                                <dl className="grid grid-cols-[110px_1fr] gap-y-1 text-muted-foreground">
                                    <dt>Duration</dt>
                                    <dd className="text-foreground">
                                        {formatMs(video.duration_ms)}
                                    </dd>
                                    <dt>Resolution</dt>
                                    <dd className="text-foreground">
                                        {video.width}×{video.height} ·{' '}
                                        {video.fps?.toFixed(1)} fps
                                    </dd>
                                    <dt>Codec</dt>
                                    <dd className="text-foreground">
                                        {video.codec}
                                    </dd>
                                    <dt>Size</dt>
                                    <dd className="text-foreground">
                                        {formatBytes(video.bytes)}
                                    </dd>
                                    <dt>Recorded</dt>
                                    <dd className="text-foreground">
                                        {video.recorded_at
                                            ? formatDateTime(
                                                  video.recorded_at,
                                                  video.recorded_timezone ??
                                                      undefined,
                                              )
                                            : 'Unknown'}
                                    </dd>
                                    <dt>Re-analysis</dt>
                                    <dd className="text-foreground">
                                        {canReanalyze
                                            ? `available until ${formatDateTime(reanalysisAvailableUntil, tenant?.timezone)}`
                                            : 'unavailable (original file expired)'}
                                    </dd>
                                </dl>
                            </div>
                        </div>

                        <section className="space-y-3">
                            <h2 className="font-medium">Kitchen zones</h2>
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
                                    Run analysis
                                </h2>
                                {ruleSets.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No active SOP rules yet.{' '}
                                        <Link
                                            className="underline"
                                            href={rules.index()}
                                        >
                                            Publish rules
                                        </Link>{' '}
                                        first.
                                    </p>
                                ) : (
                                    <form
                                        className="space-y-4"
                                        onSubmit={(e) => {
                                            e.preventDefault();
                                            form.post(
                                                analyses.store(video.id).url,
                                            );
                                        }}
                                    >
                                        <div className="grid gap-4 sm:grid-cols-2 items-start">
                                            <Field
                                                label="AI model"
                                                htmlFor="run-model"
                                                error={form.errors.model_id}
                                            >
                                                <NativeSelect
                                                    id="run-model"
                                                    value={form.data.model_id}
                                                    onChange={(e) => {
                                                        const model =
                                                            models.find(
                                                                (m) =>
                                                                    m.id ===
                                                                    e.target
                                                                        .value,
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
                                                                ? ' (simulation)'
                                                                : ppeSupported(
                                                                        m,
                                                                    )
                                                                  ? ' — PPE: hair/glove'
                                                                  : ' — person only'}
                                                        </option>
                                                    ))}
                                                </NativeSelect>
                                            </Field>
                                            <Field
                                                label="SOP rules"
                                                htmlFor="run-rules"
                                                error={
                                                    form.errors.rule_set_id
                                                }
                                            >
                                                <NativeSelect
                                                    id="run-rules"
                                                    value={
                                                        form.data.rule_set_id
                                                    }
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
                                                            {r.name} (rev{' '}
                                                            {
                                                                r
                                                                    .active_revision
                                                                    ?.version
                                                            }
                                                            )
                                                        </option>
                                                    ))}
                                                </NativeSelect>
                                            </Field>
                                        </div>
                                        <div className="flex flex-col gap-3 border-t pt-4 sm:flex-row sm:items-start sm:justify-between">
                                            <div className="min-w-0 space-y-1 text-xs text-muted-foreground">
                                                {selectedModel?.capabilities
                                                    .notes && (
                                                    <p>
                                                        {
                                                            selectedModel
                                                                .capabilities
                                                                .notes
                                                        }
                                                    </p>
                                                )}
                                                {form.data.mode ===
                                                    'simulation' && (
                                                    <p>
                                                        Simulation mode
                                                        produces synthetic
                                                        detections for workflow
                                                        demos. Results are
                                                        labeled SIMULATION and
                                                        are excluded from
                                                        compliance metrics.
                                                    </p>
                                                )}
                                                {zones.length === 0 && (
                                                    <p className="text-amber-700 dark:text-amber-400">
                                                        No zones yet: PPE and
                                                        restricted areas will
                                                        not be assessed.
                                                    </p>
                                                )}
                                                {form.errors.mode && (
                                                    <p className="text-sm text-destructive">
                                                        {form.errors.mode}
                                                    </p>
                                                )}
                                            </div>
                                            <Button
                                                className="h-9 shrink-0 sm:min-w-36"
                                                disabled={
                                                    form.processing ||
                                                    !canReanalyze
                                                }
                                                data-test="start-analysis"
                                            >
                                                {form.data.mode === 'simulation'
                                                    ? 'Run simulation'
                                                    : 'Run analysis'}
                                            </Button>
                                        </div>
                                    </form>
                                )}
                            </section>
                        )}
                    </>
                )}

                <section className="space-y-3">
                    <h2 className="font-medium">Analysis history</h2>
                    {runs.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No analysis runs for this video yet.
                        </p>
                    ) : (
                        <Table>
                            <thead>
                                <tr>
                                    <Th>Status</Th>
                                    <Th>Model</Th>
                                    <Th>Rules</Th>
                                    <Th>Progres</Th>
                                    <Th>Requested</Th>
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
    breadcrumbs: [{ title: 'Video & Analysis', href: videos.index() }],
};
