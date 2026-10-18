import { Head, Link } from '@inertiajs/react';
import {
    ChevronLeft,
    ChevronRight,
    LayoutGrid,
    Maximize2,
    VideoOff,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import {
    VideoOverlay,
    detectionBoxes,
    useObservationFrame,
} from '@/components/kitchen/video-player';
import {
    EmptyState,
    Page,
    PageHeader,
    ReviewBadge,
    SimulationBadge,
} from '@/components/kitchen/ui';
import { Button } from '@/components/ui/button';
import { eventTypeLabel, formatDateTime, formatMs } from '@/lib/kitchen';
import { cn } from '@/lib/utils';
import { monitor } from '@/routes';
import analyses from '@/routes/analyses';
import events from '@/routes/events';
import sources from '@/routes/sources';
import videos from '@/routes/videos';
import type { ReviewStatus, Zone } from '@/types/kitchen';

type Camera = {
    id: string;
    kitchen_id: string;
    name: string;
    type: string;
    video: {
        id: string;
        original_name: string;
        duration_ms: number | null;
        width: number | null;
        height: number | null;
        recorded_at: string | null;
        created_at: string;
    } | null;
    run: {
        id: string;
        mode: 'actual' | 'simulation';
        model: { name: string; version: string } | null;
        finished_at: string | null;
    } | null;
    zones: Pick<Zone, 'id' | 'name' | 'type' | 'points'>[];
    open_events: number;
    recent_events: {
        id: string;
        type: string;
        review_status: ReviewStatus;
        first_ms: number | null;
        is_simulation: boolean;
        created_at: string;
    }[];
};

type Room = { id: string; name: string; cameras: Camera[] };

type PropertyRooms = {
    id: string;
    name: string;
    timezone: string;
    rooms: Room[];
};

type Feed = Camera & {
    code: string;
    roomName: string;
    propertyName: string;
    timezone: string;
};

export default function Monitor({
    properties,
}: {
    properties: PropertyRooms[];
}) {
    const query =
        typeof window === 'undefined'
            ? new URLSearchParams()
            : new URLSearchParams(window.location.search);
    const [roomId, setRoomId] = useState<string | null>(query.get('ruangan'));
    const [focusId, setFocusId] = useState<string | null>(query.get('kamera'));
    const now = useClock();

    const feeds = useMemo<Feed[]>(
        () =>
            properties.flatMap((property) =>
                property.rooms.flatMap((room) =>
                    room.cameras.map((camera, i) => ({
                        ...camera,
                        code: `CAM-${String(i + 1).padStart(2, '0')}`,
                        roomName: room.name,
                        propertyName: property.name,
                        timezone: property.timezone,
                    })),
                ),
            ),
        [properties],
    );

    const visible = roomId
        ? feeds.filter((f) => f.kitchen_id === roomId)
        : feeds;
    const focus = feeds.find((f) => f.id === focusId) ?? null;
    const focusIndex = focus ? visible.findIndex((f) => f.id === focus.id) : -1;

    useEffect(() => {
        const params = new URLSearchParams();
        if (roomId) {
            params.set('ruangan', roomId);
        }
        if (focusId) {
            params.set('kamera', focusId);
        }
        const search = params.toString();
        window.history.replaceState(
            window.history.state,
            '',
            `${window.location.pathname}${search ? `?${search}` : ''}`,
        );
    }, [roomId, focusId]);

    const step = useCallback(
        (delta: number) => {
            if (visible.length === 0) {
                return;
            }
            const next =
                (Math.max(focusIndex, 0) + delta + visible.length) %
                visible.length;
            setFocusId(visible[next].id);
        },
        [visible, focusIndex],
    );

    useEffect(() => {
        if (!focus) {
            return;
        }
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'ArrowRight') {
                step(1);
            } else if (e.key === 'ArrowLeft') {
                step(-1);
            } else if (e.key === 'Escape') {
                setFocusId(null);
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [focus, step]);

    const rooms = visible.reduce<
        { key: string; label: string; feeds: Feed[] }[]
    >((groups, feed) => {
        const last = groups.at(-1);
        if (last && last.key === feed.kitchen_id) {
            last.feeds.push(feed);
        } else {
            groups.push({
                key: feed.kitchen_id,
                label: `${feed.propertyName} · ${feed.roomName}`,
                feeds: [feed],
            });
        }
        return groups;
    }, []);

    return (
        <>
            <Head title="Monitor CCTV" />
            <Page>
                <PageHeader
                    title="Monitor CCTV"
                    description="Monitor all cameras by room. Select a room on the left, then click cameras to inspect one at a time (left/right arrows to switch cameras)."
                />
                <p
                    className="rounded-lg border border-amber-300 bg-amber-50 px-4 py-2 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-100"
                    data-test="monitor-replay-note"
                >
                    Replay mode: each camera loops its latest recording
                    with a running clock so it looks like live video. Green boxes are AI detections from the latest
                    analysis. Live RTSP is planned for P1.
                </p>

                {feeds.length === 0 ? (
                    <EmptyState
                        title="No active cameras yet"
                        description="Add video sources under Video Sources, then upload recordings."
                        action={
                            <Button asChild size="sm">
                                <Link href={sources.index()}>Video Sources</Link>
                            </Button>
                        }
                    />
                ) : (
                    <div className="flex flex-col gap-6 lg:flex-row">
                        <nav
                            className="shrink-0 space-y-4 lg:w-64"
                            data-test="monitor-rooms"
                        >
                            <button
                                type="button"
                                onClick={() => {
                                    setRoomId(null);
                                    setFocusId(null);
                                }}
                                className={cn(
                                    'flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-sm',
                                    roomId === null
                                        ? 'bg-primary text-primary-foreground'
                                        : 'hover:bg-muted',
                                )}
                            >
                                <span className="flex items-center gap-2">
                                    <LayoutGrid className="size-4" /> All
                                    rooms
                                </span>
                                <span className="text-xs opacity-80">
                                    {feeds.length} cameras
                                </span>
                            </button>
                            {properties.map((property) => (
                                <div key={property.id} className="space-y-1">
                                    <p className="px-3 text-xs font-medium text-muted-foreground uppercase">
                                        {property.name}
                                    </p>
                                    {property.rooms.map((room) => (
                                        <div key={room.id}>
                                            <button
                                                type="button"
                                                data-test="monitor-room"
                                                onClick={() => {
                                                    setRoomId(room.id);
                                                    setFocusId(null);
                                                }}
                                                className={cn(
                                                    'flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-sm',
                                                    roomId === room.id
                                                        ? 'bg-primary text-primary-foreground'
                                                        : 'hover:bg-muted',
                                                )}
                                            >
                                                <span>{room.name}</span>
                                                <span className="text-xs opacity-80">
                                                    {
                                                        room.cameras.filter(
                                                            (c) => c.video,
                                                        ).length
                                                    }
                                                    /{room.cameras.length}{' '}
                                                    online
                                                </span>
                                            </button>
                                            {roomId === room.id && (
                                                <ul className="mt-1 ml-3 space-y-0.5 border-l pl-2">
                                                    {feeds
                                                        .filter(
                                                            (f) =>
                                                                f.kitchen_id ===
                                                                room.id,
                                                        )
                                                        .map((f) => (
                                                            <li key={f.id}>
                                                                <button
                                                                    type="button"
                                                                    onClick={() =>
                                                                        setFocusId(
                                                                            f.id,
                                                                        )
                                                                    }
                                                                    className={cn(
                                                                        'flex w-full items-center gap-2 rounded px-2 py-1 text-left text-xs',
                                                                        focusId ===
                                                                            f.id
                                                                            ? 'bg-muted font-medium'
                                                                            : 'hover:bg-muted',
                                                                    )}
                                                                >
                                                                    <span
                                                                        className={cn(
                                                                            'size-2 rounded-full',
                                                                            f.video
                                                                                ? 'bg-emerald-500'
                                                                                : 'bg-muted-foreground/40',
                                                                        )}
                                                                    />
                                                                    {f.code} ·{' '}
                                                                    {f.name}
                                                                </button>
                                                            </li>
                                                        ))}
                                                </ul>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            ))}
                        </nav>

                        <div className="min-w-0 flex-1">
                            {focus ? (
                                <FocusView
                                    feed={focus}
                                    now={now}
                                    position={`${focusIndex + 1}/${visible.length}`}
                                    onBack={() => setFocusId(null)}
                                    onStep={step}
                                />
                            ) : visible.length === 0 ? (
                                <EmptyState
                                    title="This room has no cameras yet"
                                    description="Add a video source for this kitchen under Video Sources."
                                />
                            ) : (
                                <div className="space-y-6">
                                    {rooms.map((group) => (
                                        <section
                                            key={group.key}
                                            className="space-y-2"
                                        >
                                            <h2 className="text-sm font-medium">
                                                {group.label}
                                            </h2>
                                            <div className="grid gap-3 md:grid-cols-2 2xl:grid-cols-3">
                                                {group.feeds.map((feed) => (
                                                    <CctvFeed
                                                        key={feed.id}
                                                        feed={feed}
                                                        now={now}
                                                        onSelect={() =>
                                                            setFocusId(feed.id)
                                                        }
                                                    />
                                                ))}
                                            </div>
                                        </section>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>
                )}
            </Page>
        </>
    );
}

function useClock(): Date {
    const [now, setNow] = useState(() => new Date());

    useEffect(() => {
        const timer = setInterval(() => setNow(new Date()), 1000);

        return () => clearInterval(timer);
    }, []);

    return now;
}

function formatClock(now: Date, timezone: string): string {
    return new Intl.DateTimeFormat('id-ID', {
        timeZone: timezone,
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
    }).format(now);
}

function CctvFeed({
    feed,
    now,
    large = false,
    onSelect,
}: {
    feed: Feed;
    now: Date;
    large?: boolean;
    onSelect?: () => void;
}) {
    const [timeMs, setTimeMs] = useState(0);
    const [showZones, setShowZones] = useState(large);
    const [showBoxes, setShowBoxes] = useState(true);
    const runId = feed.run?.id;
    const observationsUrl = useMemo(
        () =>
            runId
                ? (from: number, to: number) =>
                      analyses.observations(runId, {
                          query: { from_ms: from, to_ms: to },
                      }).url
                : undefined,
        [runId],
    );
    const { current } = useObservationFrame(observationsUrl, timeMs);
    const people = current?.detections.length ?? 0;
    const aspect =
        feed.video?.width && feed.video.height
            ? feed.video.width / feed.video.height
            : 16 / 9;
    const frame = large ? aspect : 16 / 9;

    return (
        <div className="space-y-2">
            <div
                role={onSelect ? 'button' : undefined}
                tabIndex={onSelect ? 0 : undefined}
                onClick={onSelect}
                onKeyDown={(e) => {
                    if (onSelect && (e.key === 'Enter' || e.key === ' ')) {
                        e.preventDefault();
                        onSelect();
                    }
                }}
                data-test="cctv-feed"
                className={cn(
                    'group overflow-hidden rounded-lg bg-neutral-950 ring-1 ring-black/10',
                    onSelect &&
                        'cursor-pointer outline-none hover:ring-2 hover:ring-primary focus-visible:ring-2 focus-visible:ring-primary',
                )}
            >
                <div className="flex items-start justify-between gap-2 border-b border-white/10 px-2 py-1.5 font-mono text-[11px] text-white">
                    <div className="min-w-0">
                        <p className="truncate font-semibold">
                            {feed.code} · {feed.name}
                        </p>
                        <p className="truncate opacity-75">{feed.roomName}</p>
                    </div>
                    {feed.video && (
                        <div className="flex shrink-0 items-center gap-2">
                            <span className="rounded bg-white/15 px-1.5 py-0.5 text-[10px] tracking-wider">
                                REPLAY
                            </span>
                            <span className="flex items-center gap-1 font-semibold">
                                <span className="size-2 animate-pulse rounded-full bg-red-500" />
                                REC
                            </span>
                        </div>
                    )}
                </div>
                <div
                    className="relative flex items-center justify-center"
                    style={{ aspectRatio: frame }}
                >
                    {feed.video ? (
                        <div
                            className="relative"
                            style={
                                aspect < frame
                                    ? { height: '100%', aspectRatio: aspect }
                                    : { width: '100%', aspectRatio: aspect }
                            }
                        >
                            <video
                                src={videos.preview(feed.video.id).url}
                                autoPlay
                                muted
                                loop
                                playsInline
                                preload="auto"
                                className="absolute inset-0 h-full w-full object-fill"
                                onTimeUpdate={(e) =>
                                    setTimeMs(
                                        Math.round(
                                            e.currentTarget.currentTime * 1000,
                                        ),
                                    )
                                }
                            />
                            <VideoOverlay
                                zones={showZones ? feed.zones : []}
                                boxes={showBoxes ? detectionBoxes(current) : []}
                                compact={!large}
                            />
                        </div>
                    ) : (
                        <div className="absolute inset-0 flex flex-col items-center justify-center gap-2 bg-[repeating-linear-gradient(0deg,#171717_0px,#171717_2px,#0a0a0a_2px,#0a0a0a_4px)] text-neutral-400">
                            <VideoOff className="size-8" />
                            <span className="font-mono text-sm tracking-widest">
                                NO SIGNAL
                            </span>
                            <span className="text-xs">
                                No ready recording for this camera yet
                            </span>
                        </div>
                    )}

                    <div className="pointer-events-none absolute inset-x-0 bottom-0 flex items-end justify-between bg-gradient-to-t from-black/70 to-transparent p-2 font-mono text-[11px] text-white">
                        <span data-test="cctv-clock">
                            {formatClock(now, feed.timezone)}
                        </span>
                        <span className="flex items-center gap-2">
                            {feed.run && (
                                <span>
                                    {people} {people === 1 ? 'person' : 'people'}
                                </span>
                            )}
                            {feed.open_events > 0 && (
                                <span className="rounded bg-red-600 px-1.5 py-0.5">
                                    {feed.open_events}{' '}
                                    {feed.open_events === 1
                                        ? 'anomaly'
                                        : 'anomalies'}
                                </span>
                            )}
                        </span>
                    </div>
                    {onSelect && (
                        <Maximize2 className="pointer-events-none absolute top-1/2 left-1/2 size-8 -translate-x-1/2 -translate-y-1/2 text-white opacity-0 drop-shadow transition-opacity group-hover:opacity-80" />
                    )}
                </div>
            </div>
            {large && feed.video && (
                <div className="flex flex-wrap items-center gap-4 text-xs text-muted-foreground">
                    <span className="font-mono">
                        Recording position {formatMs(timeMs)} /{' '}
                        {formatMs(feed.video.duration_ms)}
                    </span>
                    <label className="flex items-center gap-1">
                        <input
                            type="checkbox"
                            checked={showZones}
                            onChange={(e) => setShowZones(e.target.checked)}
                        />{' '}
                        Zones
                    </label>
                    <label className="flex items-center gap-1">
                        <input
                            type="checkbox"
                            checked={showBoxes}
                            onChange={(e) => setShowBoxes(e.target.checked)}
                        />{' '}
                        AI detections
                    </label>
                </div>
            )}
        </div>
    );
}

function FocusView({
    feed,
    now,
    position,
    onBack,
    onStep,
}: {
    feed: Feed;
    now: Date;
    position: string;
    onBack: () => void;
    onStep: (delta: number) => void;
}) {
    return (
        <div className="space-y-4" data-test="cctv-focus">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <Button variant="ghost" size="sm" onClick={onBack}>
                    <LayoutGrid className="size-4" /> Back to grid
                </Button>
                <div className="flex items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => onStep(-1)}
                        aria-label="Previous camera"
                    >
                        <ChevronLeft className="size-4" />
                    </Button>
                    <span className="text-sm text-muted-foreground tabular-nums">
                        Camera {position}
                    </span>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => onStep(1)}
                        aria-label="Next camera"
                    >
                        <ChevronRight className="size-4" />
                    </Button>
                </div>
            </div>
            <div className="grid gap-4 xl:grid-cols-[1fr_20rem]">
                <CctvFeed key={feed.id} feed={feed} now={now} large />
                <aside className="space-y-4 text-sm">
                    <div className="space-y-1 rounded-xl border p-4">
                        <p className="font-medium">
                            {feed.code} · {feed.name}
                        </p>
                        <p className="text-muted-foreground">
                            {feed.propertyName} · {feed.roomName}
                        </p>
                        {feed.video ? (
                            <>
                                <p className="pt-2 text-xs text-muted-foreground">
                                    Recording: {feed.video.original_name} (
                                    {formatMs(feed.video.duration_ms)})
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Recorded{' '}
                                    {formatDateTime(
                                        feed.video.recorded_at ??
                                            feed.video.created_at,
                                        feed.timezone,
                                    )}
                                </p>
                            </>
                        ) : (
                            <p className="pt-2 text-xs text-muted-foreground">
                                No recording yet. Upload video from Video
                                & Analysis.
                            </p>
                        )}
                        {feed.run ? (
                            <p className="flex items-center gap-2 text-xs text-muted-foreground">
                                Detections: {feed.run.model?.name}{' '}
                                {feed.run.model?.version}
                                {feed.run.mode === 'simulation' && (
                                    <SimulationBadge />
                                )}
                            </p>
                        ) : (
                            feed.video && (
                                <p className="text-xs text-muted-foreground">
                                    This recording has not been analysed yet.
                                </p>
                            )
                        )}
                        <div className="flex flex-wrap gap-2 pt-2">
                            {feed.video && (
                                <Button asChild size="sm" variant="outline">
                                    <Link href={videos.show(feed.video.id)}>
                                        Open video & zones
                                    </Link>
                                </Button>
                            )}
                            {feed.run && (
                                <Button asChild size="sm" variant="outline">
                                    <Link href={analyses.show(feed.run.id)}>
                                        Analysis results
                                    </Link>
                                </Button>
                            )}
                        </div>
                    </div>
                    <div className="space-y-2 rounded-xl border p-4">
                        <p className="font-medium">Recent anomalies</p>
                        {feed.recent_events.length === 0 ? (
                            <p className="text-xs text-muted-foreground">
                                No anomalies from this camera yet.
                            </p>
                        ) : (
                            <ul className="space-y-2">
                                {feed.recent_events.map((event) => (
                                    <li key={event.id}>
                                        <Link
                                            href={events.show(event.id)}
                                            className="flex items-center justify-between gap-2 rounded px-1 py-0.5 hover:bg-muted"
                                        >
                                            <span>
                                                {eventTypeLabel(event.type)}
                                                <span className="block text-xs text-muted-foreground">
                                                    at{' '}
                                                    {formatMs(event.first_ms)}
                                                    {event.is_simulation &&
                                                        ' · simulation'}
                                                </span>
                                            </span>
                                            <ReviewBadge
                                                status={event.review_status}
                                            />
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </aside>
            </div>
        </div>
    );
}

Monitor.layout = {
    breadcrumbs: [{ title: 'Monitor CCTV', href: monitor() }],
};
