import { Head, Link, router } from '@inertiajs/react';
import {
    EmptyState,
    NativeSelect,
    Page,
    PageHeader,
    Pagination,
    SimulationBadge,
    Table,
    Td,
    Th,
} from '@/components/kitchen/ui';
import {
    activityLabel,
    formatDateTime,
    formatMs,
    useTenant,
    ZONE_TYPES,
    zoneColor,
} from '@/lib/kitchen';
import activities from '@/routes/activities';
import analyses from '@/routes/analyses';
import type { ActivitySegment, Paginated } from '@/types/kitchen';

type Props = {
    runs: {
        id: string;
        mode: string;
        created_at: string;
        video?: { id: string; original_name: string } | null;
    }[];
    selectedRunId: string | null;
    segments: Paginated<ActivitySegment>;
    totals: { label: string; ms: number }[];
    filters: { run_id?: string; label?: string; zone_type?: string };
    labels: string[];
};

export default function Activities({
    runs,
    selectedRunId,
    segments,
    totals,
    filters,
    labels,
}: Props) {
    const { tenant } = useTenant();
    const apply = (next: Partial<Props['filters']>) =>
        router.get(
            activities.index().url,
            { ...filters, run_id: selectedRunId ?? undefined, ...next },
            { preserveState: true, replace: true },
        );
    const totalMs = totals.reduce((sum, t) => sum + Number(t.ms), 0) || 1;

    return (
        <>
            <Head title="Activities" />
            <Page>
                <PageHeader
                    title="Activities"
                    description="Person presence intervals in each zone. Labels come from foot position against zone polygons, not cooking-activity recognition."
                />

                {runs.length === 0 ? (
                    <EmptyState
                        title="No completed analyses yet"
                        description="Activities appear after video analysis completes."
                    />
                ) : (
                    <>
                        <div className="grid gap-3 rounded-xl border bg-card p-4 sm:grid-cols-2 lg:grid-cols-3 items-start">
                            <NativeSelect
                                className="h-9 w-full min-w-0 lg:col-span-2"
                                aria-label="Analysis run"
                                value={selectedRunId ?? ''}
                                onChange={(e) =>
                                    apply({
                                        run_id: e.target.value,
                                        label: undefined,
                                    })
                                }
                            >
                                {runs.map((r) => (
                                    <option key={r.id} value={r.id}>
                                        {r.video?.original_name} ·{' '}
                                        {formatDateTime(
                                            r.created_at,
                                            tenant?.timezone,
                                        )}
                                        {r.mode === 'simulation'
                                            ? ' (simulation)'
                                            : ''}
                                    </option>
                                ))}
                            </NativeSelect>
                            <NativeSelect
                                className="h-9 w-full min-w-0"
                                aria-label="Activity type"
                                value={filters.label ?? ''}
                                onChange={(e) =>
                                    apply({
                                        label: e.target.value || undefined,
                                    })
                                }
                            >
                                <option value="">All activities</option>
                                {labels.map((l) => (
                                    <option key={l} value={l}>
                                        {activityLabel(l)}
                                    </option>
                                ))}
                            </NativeSelect>
                            <NativeSelect
                                className="h-9 w-full min-w-0"
                                aria-label="Zone type"
                                value={filters.zone_type ?? ''}
                                onChange={(e) =>
                                    apply({
                                        zone_type: e.target.value || undefined,
                                    })
                                }
                            >
                                <option value="">All zone types</option>
                                {ZONE_TYPES.map((z) => (
                                    <option key={z.value} value={z.value}>
                                        {z.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                        {selectedRunId && (
                            <p className="text-sm">
                                <Link
                                    href={analyses.show(selectedRunId)}
                                    className="text-muted-foreground hover:underline"
                                >
                                    Open analysis timeline
                                </Link>
                            </p>
                        )}

                        {runs.find((r) => r.id === selectedRunId)?.mode ===
                            'simulation' && (
                            <p
                                className="flex items-center gap-2 rounded-lg border border-violet-300 bg-violet-50 px-4 py-2 text-sm text-violet-900 dark:border-violet-800 dark:bg-violet-950 dark:text-violet-100"
                                data-test="simulation-note"
                            >
                                <SimulationBadge />
                                People and activities below were generated by the
                                simulator for workflow demos, not detection
                                results from video.
                            </p>
                        )}

                        {totals.length > 0 && (
                            <div className="space-y-2 rounded-xl border p-4">
                                <p className="text-sm font-medium">
                                    Total duration by activity (all people)
                                </p>
                                <div className="flex h-4 overflow-hidden rounded">
                                    {totals.map((t) => (
                                        <div
                                            key={t.label}
                                            title={`${activityLabel(t.label)} ${formatMs(Number(t.ms))}`}
                                            style={{
                                                width: `${(Number(t.ms) / totalMs) * 100}%`,
                                                background: zoneColor(
                                                    t.label.replace(
                                                        /^in_|_zone$/g,
                                                        '',
                                                    ),
                                                ),
                                            }}
                                        />
                                    ))}
                                </div>
                                <div className="flex flex-wrap gap-4 text-xs">
                                    {totals.map((t) => (
                                        <span
                                            key={t.label}
                                            className="flex items-center gap-1"
                                        >
                                            <span
                                                className="size-2 rounded-sm"
                                                style={{
                                                    background: zoneColor(
                                                        t.label.replace(
                                                            /^in_|_zone$/g,
                                                            '',
                                                        ),
                                                    ),
                                                }}
                                            />
                                            {activityLabel(t.label)}{' '}
                                            <span className="text-muted-foreground tabular-nums">
                                                {formatMs(Number(t.ms))}
                                            </span>
                                        </span>
                                    ))}
                                </div>
                            </div>
                        )}

                        <Table>
                            <thead>
                                <tr>
                                    <Th>People</Th>
                                    <Th>Activity</Th>
                                    <Th>Zone</Th>
                                    <Th>Started</Th>
                                    <Th>Ended</Th>
                                    <Th>Duration</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {segments.data.map((s) => (
                                    <tr key={s.id}>
                                        <Td>Person {s.track?.track_key}</Td>
                                        <Td>{activityLabel(s.label)}</Td>
                                        <Td>{s.zone?.name ?? '-'}</Td>
                                        <Td className="font-mono">
                                            {formatMs(s.start_ms)}
                                        </Td>
                                        <Td className="font-mono">
                                            {formatMs(s.end_ms)}
                                        </Td>
                                        <Td className="font-mono">
                                            {formatMs(s.end_ms - s.start_ms)}
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                        <Pagination page={segments} />
                    </>
                )}
            </Page>
        </>
    );
}

Activities.layout = {
    breadcrumbs: [{ title: 'Activities', href: activities.index() }],
};
