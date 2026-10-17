import { Head, Link, router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Timeline } from '@/components/kitchen/timeline';
import { VideoPlayer } from '@/components/kitchen/video-player';
import type { PlayerHandle } from '@/components/kitchen/video-player';
import {
    CategoryBadge,
    Page,
    PageErrors,
    PageHeader,
    ReviewBadge,
    RunStatusBadge,
    SimulationBadge,
    Table,
    Td,
    Th,
} from '@/components/kitchen/ui';
import { Button } from '@/components/ui/button';
import {
    eventTypeLabel,
    formatDateTime,
    formatMs,
    getJson,
    ruleRevisionLabel,
    useTenant,
} from '@/lib/kitchen';
import analyses from '@/routes/analyses';
import events from '@/routes/events';
import videos from '@/routes/videos';
import type { AnomalyEvent, Run, RunStatus, Track } from '@/types/kitchen';

type Props = {
    run: Run & { video: NonNullable<Run['video']> };
    tracks: Track[];
    events: AnomalyEvent[];
    observationsExpired: boolean;
    observationChunkMs: number;
};

type Status = {
    status: RunStatus;
    phase: string | null;
    progress: number;
    processed_ms: number;
    error_code: string | null;
    error_message: string | null;
    event_count: number;
    queue_position: number | null;
    cancel_requested_at: string | null;
};

const PHASES: Record<string, string> = {
    queued: 'Waiting for worker',
    dispatching: 'Dispatching to worker',
    preparing: 'Preparing video',
    detecting: 'Detecting people & PPE',
    finalizing: 'Finalizing results',
};

export default function AnalysisShow({
    run,
    tracks,
    events: eventList,
    observationsExpired,
}: Props) {
    const { can, tenant } = useTenant();
    const playerRef = useRef<PlayerHandle>(null);
    const [playhead, setPlayhead] = useState(0);
    const [live, setLive] = useState<Status | null>(null);
    const active = run.status === 'QUEUED' || run.status === 'RUNNING';

    useEffect(() => {
        if (!active) {
            return;
        }
        let lastCount = eventList.length;
        const timer = setInterval(() => {
            getJson<Status>(analyses.status(run.id).url)
                .then((s) => {
                    setLive(s);
                    if (
                        s.status !== run.status ||
                        s.event_count !== lastCount
                    ) {
                        lastCount = s.event_count;
                        router.reload({ only: ['run', 'tracks', 'events'] });
                    }
                })
                .catch(() => undefined);
        }, 2000);

        return () => clearInterval(timer);
    }, [active, run.id, run.status, eventList.length]);

    const observationsUrl = useCallback(
        (from: number, to: number) =>
            analyses.observations(run.id, {
                query: { from_ms: from, to_ms: to },
            }).url,
        [run.id],
    );

    const status = live ?? {
        ...run,
        event_count: eventList.length,
        queue_position: null,
    };
    const zones = run.input_snapshot?.zones ?? [];

    return (
        <>
            <Head title="Analysis results" />
            <Page>
                <PageHeader
                    title={`Analysis ${run.video.original_name}`}
                    description={`${run.model?.name} ${run.model?.version} · ${ruleRevisionLabel(run.rule_revision)} · requested ${run.requester?.name ?? '-'} ${formatDateTime(run.created_at, tenant?.timezone)}`}
                    actions={
                        <>
                            {run.mode === 'simulation' && <SimulationBadge />}
                            <RunStatusBadge status={status.status} />
                            {active &&
                                can('run_analysis') &&
                                !status.cancel_requested_at && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            router.post(
                                                analyses.cancel(run.id).url,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Cancel
                                    </Button>
                                )}
                            <Button asChild variant="ghost" size="sm">
                                <Link href={videos.show(run.video.id)}>
                                    Back to video
                                </Link>
                            </Button>
                        </>
                    }
                />
                <PageErrors keys={['run']} />

                {run.mode === 'simulation' && (
                    <p className="rounded-lg border border-violet-300 bg-violet-50 px-4 py-2 text-sm text-violet-900 dark:bg-violet-950 dark:text-violet-100">
                        These results were generated by the simulator for workflow demos,
                        not real detections, and are excluded from compliance
                        metrics.
                    </p>
                )}

                {(active || status.status === 'FAILED') && (
                    <div className="space-y-2 rounded-xl border p-4">
                        <div className="flex items-center justify-between text-sm">
                            <span>
                                {status.status === 'FAILED'
                                    ? `Failed: ${status.error_message ?? status.error_code}`
                                    : status.cancel_requested_at
                                      ? 'Waiting for worker to stop…'
                                      : (PHASES[status.phase ?? 'queued'] ??
                                        status.phase)}
                                {status.queue_position
                                    ? ` · queue position ${status.queue_position}`
                                    : ''}
                            </span>
                            <span className="text-muted-foreground tabular-nums">
                                {formatMs(status.processed_ms)} /{' '}
                                {formatMs(run.video.duration_ms)} ·{' '}
                                {status.progress}%
                            </span>
                        </div>
                        <div className="h-2 rounded bg-muted">
                            <div
                                className="h-full rounded bg-primary transition-all"
                                style={{ width: `${status.progress}%` }}
                            />
                        </div>
                        {active && (
                            <p className="text-xs text-muted-foreground">
                                Closed events may appear while analysis
                                is running. {status.event_count} event
                                {status.event_count === 1 ? '' : 's'} so far.
                            </p>
                        )}
                    </div>
                )}

                {run.video.preview_key && (
                    <VideoPlayer
                        src={videos.preview(run.video.id).url}
                        zones={zones}
                        observationsUrl={
                            observationsExpired ? undefined : observationsUrl
                        }
                        playerRef={playerRef}
                        onTime={setPlayhead}
                    />
                )}

                <section className="space-y-3 rounded-xl border p-4">
                    <h2 className="font-medium">Timeline by person</h2>
                    <Timeline
                        durationMs={run.video.duration_ms ?? 0}
                        tracks={tracks}
                        events={eventList}
                        playheadMs={playhead}
                        onSeek={(ms) => playerRef.current?.seek(ms)}
                    />
                </section>

                <section className="space-y-3">
                    <h2 className="font-medium">Event ({eventList.length})</h2>
                    {eventList.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {active
                                ? 'No events yet.'
                                : 'No events in this analysis run.'}
                        </p>
                    ) : (
                        <Table>
                            <thead>
                                <tr>
                                    <Th>Time</Th>
                                    <Th>Type</Th>
                                    <Th>Category</Th>
                                    <Th>Status review</Th>
                                    <Th />
                                </tr>
                            </thead>
                            <tbody>
                                {eventList.map((e) => (
                                    <tr key={e.id}>
                                        <Td>
                                            <button
                                                type="button"
                                                className="font-mono hover:underline"
                                                onClick={() =>
                                                    playerRef.current?.seek(
                                                        e.first_ms ?? 0,
                                                    )
                                                }
                                            >
                                                {formatMs(e.first_ms)}–
                                                {formatMs(e.last_ms)}
                                            </button>
                                        </Td>
                                        <Td>{eventTypeLabel(e.type)}</Td>
                                        <Td>
                                            <CategoryBadge
                                                category={e.category}
                                            />
                                        </Td>
                                        <Td>
                                            <ReviewBadge
                                                status={e.review_status}
                                            />
                                        </Td>
                                        <Td className="text-right">
                                            <Link
                                                href={events.show(e.id)}
                                                className="text-sm hover:underline"
                                            >
                                                Tinjau
                                            </Link>
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

AnalysisShow.layout = {
    breadcrumbs: [{ title: 'Video & Analysis', href: videos.index() }],
};
