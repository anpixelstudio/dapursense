import {
    activityLabel,
    eventTypeLabel,
    formatMs,
    ppeLabel,
    zoneColor,
} from '@/lib/kitchen';
import type { AnomalyEvent, Track } from '@/types/kitchen';

const PPE_COLORS: Record<string, string> = {
    present: '#22c55e',
    missing: '#ef4444',
    unknown: '#a1a1aa',
};

function zoneTypeOf(label: string): string {
    const match = /^in_(\w+)_zone$/.exec(label);
    return match ? match[1] : '';
}

/**
 * One row per person: zone presence on top, required-PPE state below, and
 * event markers. Clicking anything seeks the player.
 */
export function Timeline({
    durationMs,
    tracks,
    events,
    playheadMs,
    onSeek,
}: {
    durationMs: number;
    tracks: Track[];
    events: Pick<
        AnomalyEvent,
        'id' | 'type' | 'first_ms' | 'last_ms' | 'track_id' | 'category'
    >[];
    playheadMs: number;
    onSeek: (ms: number) => void;
}) {
    const total = Math.max(durationMs, 1);
    const pct = (ms: number) => `${Math.min(100, (ms / total) * 100)}%`;
    const width = (a: number, b: number) =>
        `${Math.max(0.3, ((b - a) / total) * 100)}%`;

    if (tracks.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                No people detected yet.
            </p>
        );
    }

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap gap-3 text-xs text-muted-foreground">
                <span className="flex items-center gap-1">
                    <span
                        className="size-2 rounded-sm"
                        style={{ background: PPE_COLORS.present }}
                    />{' '}
                    PPE present
                </span>
                <span className="flex items-center gap-1">
                    <span
                        className="size-2 rounded-sm"
                        style={{ background: PPE_COLORS.missing }}
                    />{' '}
                    PPE not detected
                </span>
                <span className="flex items-center gap-1">
                    <span
                        className="size-2 rounded-sm"
                        style={{ background: PPE_COLORS.unknown }}
                    />{' '}
                    Cannot assess
                </span>
            </div>
            {tracks.map((track) => {
                const trackEvents = events.filter(
                    (e) => e.track_id === track.id,
                );
                const items = [
                    ...new Set(track.ppe_assessments.map((p) => p.item_code)),
                ];
                return (
                    <div
                        key={track.id}
                        className="grid grid-cols-[90px_1fr] items-start gap-2"
                    >
                        <div className="pt-1 text-xs font-medium">
                            Person {track.track_key}
                        </div>
                        <div className="relative space-y-1">
                            <div className="relative h-5 rounded bg-muted">
                                {track.activity_segments.map((s) => (
                                    <button
                                        key={s.id}
                                        type="button"
                                        title={`${activityLabel(s.label)} ${formatMs(s.start_ms)}–${formatMs(s.end_ms)}`}
                                        className="absolute top-0 h-full rounded-sm opacity-80 hover:opacity-100"
                                        style={{
                                            left: pct(s.start_ms),
                                            width: width(s.start_ms, s.end_ms),
                                            background: zoneColor(
                                                zoneTypeOf(s.label),
                                            ),
                                        }}
                                        onClick={() => onSeek(s.start_ms)}
                                    />
                                ))}
                            </div>
                            {items.map((item) => (
                                <div
                                    key={item}
                                    className="relative h-2.5 rounded bg-muted"
                                    title={ppeLabel(item)}
                                >
                                    {track.ppe_assessments
                                        .filter((p) => p.item_code === item)
                                        .map((p) => (
                                            <button
                                                key={p.id}
                                                type="button"
                                                title={`${ppeLabel(item)}: ${p.state} ${formatMs(p.start_ms)}–${formatMs(p.end_ms)}`}
                                                className="absolute top-0 h-full rounded-sm"
                                                style={{
                                                    left: pct(p.start_ms),
                                                    width: width(
                                                        p.start_ms,
                                                        p.end_ms,
                                                    ),
                                                    background:
                                                        PPE_COLORS[p.state],
                                                }}
                                                onClick={() =>
                                                    onSeek(p.start_ms)
                                                }
                                            />
                                        ))}
                                </div>
                            ))}
                            {trackEvents.map((e) => (
                                <button
                                    key={e.id}
                                    type="button"
                                    title={eventTypeLabel(e.type)}
                                    className="absolute -top-1 h-7 w-1 rounded bg-red-600 ring-2 ring-background"
                                    style={{ left: pct(e.first_ms ?? 0) }}
                                    onClick={() => onSeek(e.first_ms ?? 0)}
                                />
                            ))}
                            <div
                                className="pointer-events-none absolute -top-1 h-[calc(100%+8px)] w-px bg-foreground"
                                style={{ left: pct(playheadMs) }}
                            />
                        </div>
                    </div>
                );
            })}
            <div className="grid grid-cols-[90px_1fr] gap-2 text-[11px] text-muted-foreground">
                <span />
                <div className="flex justify-between font-mono">
                    <span>00:00</span>
                    <span>{formatMs(total / 2)}</span>
                    <span>{formatMs(total)}</span>
                </div>
            </div>
        </div>
    );
}
