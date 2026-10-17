import { useEffect, useRef, useState } from 'react';
import type { RefObject } from 'react';
import { formatMs, getJson, ppeLabel, zoneColor } from '@/lib/kitchen';
import type { ObservationFrame, Zone } from '@/types/kitchen';

export type PlayerHandle = { seek: (ms: number) => void };

type OverlayBox = { label: string; bbox: number[]; color: string };

const WINDOW_MS = 30000;
const MATCH_MS = 500;

/**
 * Plays the private H.264 preview and draws zones plus AI detections for the
 * current playhead.
 */
export function VideoPlayer({
    src,
    zones = [],
    observationsUrl,
    staticBoxes = [],
    playerRef,
    onTime,
    startAt,
}: {
    src: string;
    zones?: Zone[];
    observationsUrl?: (fromMs: number, toMs: number) => string;
    staticBoxes?: OverlayBox[];
    playerRef?: RefObject<PlayerHandle | null>;
    onTime?: (ms: number) => void;
    startAt?: number;
}) {
    const videoRef = useRef<HTMLVideoElement>(null);
    const [timeMs, setTimeMs] = useState(0);
    const { current, expired } = useObservationFrame(observationsUrl, timeMs);
    const [error, setError] = useState<string | null>(null);
    const [showZones, setShowZones] = useState(true);
    const [showBoxes, setShowBoxes] = useState(true);

    useEffect(() => {
        if (playerRef) {
            playerRef.current = {
                seek: (ms: number) => {
                    if (videoRef.current) {
                        videoRef.current.currentTime = ms / 1000;
                        void videoRef.current.play().catch(() => undefined);
                    }
                },
            };
        }
    }, [playerRef]);

    const boxes: OverlayBox[] = [...staticBoxes, ...detectionBoxes(current)];

    return (
        <div className="space-y-2">
            <div className="relative overflow-hidden rounded-xl bg-black">
                <video
                    ref={videoRef}
                    src={src}
                    controls
                    preload="metadata"
                    playsInline
                    className="block w-full"
                    onLoadedMetadata={() => {
                        if (startAt && videoRef.current) {
                            videoRef.current.currentTime = startAt / 1000;
                        }
                    }}
                    onTimeUpdate={(e) => {
                        const ms = Math.round(
                            e.currentTarget.currentTime * 1000,
                        );
                        setTimeMs(ms);
                        onTime?.(ms);
                    }}
                    onError={() =>
                        setError(
                            'Video tidak dapat diputar. File mungkin sudah kedaluwarsa sesuai retensi.',
                        )
                    }
                />
                <VideoOverlay
                    zones={showZones ? zones : []}
                    boxes={showBoxes ? boxes : []}
                />
            </div>
            <div className="flex flex-wrap items-center gap-4 text-xs text-muted-foreground">
                <span className="font-mono">{formatMs(timeMs)}</span>
                <label className="flex items-center gap-1">
                    <input
                        type="checkbox"
                        checked={showZones}
                        onChange={(e) => setShowZones(e.target.checked)}
                    />{' '}
                    Zona
                </label>
                <label className="flex items-center gap-1">
                    <input
                        type="checkbox"
                        checked={showBoxes}
                        onChange={(e) => setShowBoxes(e.target.checked)}
                    />{' '}
                    Deteksi AI
                </label>
                {expired && (
                    <span>
                        Observasi per frame sudah kedaluwarsa; event dan
                        interval tetap tersedia.
                    </span>
                )}
                {error && <span className="text-destructive">{error}</span>}
            </div>
        </div>
    );
}

export function detectionBoxes(frame: ObservationFrame | null): OverlayBox[] {
    return (frame?.detections ?? []).map((d) => {
        const missing = Object.entries(d.ppe)
            .filter(([, s]) => s === 'missing')
            .map(([i]) => ppeLabel(i));
        return {
            label: `Orang ${d.track_key}${missing.length ? ` · tanpa ${missing.join(', ')}` : ''}`,
            bbox: d.bbox,
            color: missing.length ? '#ef4444' : '#22c55e',
        };
    });
}

/** Zone polygons and detection boxes in normalized coordinates over a video. */
export function VideoOverlay({
    zones,
    boxes,
    compact = false,
}: {
    zones: Pick<Zone, 'id' | 'type' | 'points'>[];
    boxes: OverlayBox[];
    compact?: boolean;
}) {
    return (
        <>
            <svg
                viewBox="0 0 1 1"
                preserveAspectRatio="none"
                className="pointer-events-none absolute inset-0 h-full w-full"
            >
                {zones.map((zone) => (
                    <polygon
                        key={zone.id}
                        points={zone.points.map((p) => p.join(',')).join(' ')}
                        fill={zoneColor(zone.type)}
                        fillOpacity={0.15}
                        stroke={zoneColor(zone.type)}
                        strokeWidth={0.003}
                    />
                ))}
                {boxes.map((box, i) => (
                    <rect
                        key={i}
                        x={box.bbox[0]}
                        y={box.bbox[1]}
                        width={box.bbox[2] - box.bbox[0]}
                        height={box.bbox[3] - box.bbox[1]}
                        fill="none"
                        stroke={box.color}
                        strokeWidth={0.004}
                    />
                ))}
            </svg>
            {boxes.map((box, i) => (
                <span
                    key={i}
                    className={
                        compact
                            ? 'pointer-events-none absolute rounded px-1 text-[9px] font-medium text-white'
                            : 'pointer-events-none absolute rounded px-1 text-[11px] font-medium text-white'
                    }
                    style={{
                        left: `${box.bbox[0] * 100}%`,
                        top: `calc(${box.bbox[1] * 100}% - ${compact ? 14 : 18}px)`,
                        background: box.color,
                    }}
                >
                    {box.label}
                </span>
            ))}
        </>
    );
}

/**
 * Returns the detection frame for the playhead. Observations are fetched in
 * 30 s windows around the playhead instead of loading the whole run.
 */
export function useObservationFrame(
    observationsUrl: ((fromMs: number, toMs: number) => string) | undefined,
    timeMs: number,
): { current: ObservationFrame | null; expired: boolean } {
    const [frames, setFrames] = useState<ObservationFrame[]>([]);
    const [loadedWindow, setLoadedWindow] = useState<[number, number] | null>(
        null,
    );
    const [expired, setExpired] = useState(false);

    useEffect(() => {
        if (!observationsUrl || expired) {
            return;
        }
        if (
            loadedWindow &&
            timeMs >= loadedWindow[0] &&
            timeMs <= loadedWindow[1] - 2000
        ) {
            return;
        }
        const from = Math.max(0, timeMs - 2000);
        const to = from + WINDOW_MS;
        setLoadedWindow([from, to]);
        getJson<{ expired: boolean; frames: ObservationFrame[] }>(
            observationsUrl(from, to),
        )
            .then((data) => {
                setExpired(data.expired);
                setFrames(data.frames);
            })
            .catch(() => setFrames([]));
    }, [timeMs, observationsUrl, loadedWindow, expired]);

    let current: ObservationFrame | null = null;
    for (const frame of frames) {
        if (
            frame.source_timestamp_ms <= timeMs + 50 &&
            timeMs - frame.source_timestamp_ms <= MATCH_MS
        ) {
            current = frame;
        }
    }

    return { current, expired };
}
