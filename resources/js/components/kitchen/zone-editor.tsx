import { router } from '@inertiajs/react';
import { Copy, Pencil, Plus, Trash2, X } from 'lucide-react';
import { useRef, useState } from 'react';
import type { PointerEvent as ReactPointerEvent } from 'react';
import { ConfirmDialog } from '@/components/kitchen/confirm-dialog';
import { Field, NativeSelect } from '@/components/kitchen/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ZONE_TYPES, zoneColor, zoneLabel } from '@/lib/kitchen';
import zonesRoutes from '@/routes/zones';
import type { Point, Zone, ZoneType } from '@/types/kitchen';

type Draft = {
    id: string | null;
    name: string;
    type: ZoneType;
    points: Point[];
};

const clamp = (v: number) => Math.min(1, Math.max(0, v));
const round = (v: number) => Math.round(v * 100000) / 100000;

export function ZoneEditor({
    sourceId,
    imageUrl,
    zones,
    otherSources,
    canEdit,
}: {
    sourceId: string;
    imageUrl: string;
    zones: Zone[];
    otherSources: { id: string; name: string }[];
    canEdit: boolean;
}) {
    const svgRef = useRef<SVGSVGElement>(null);
    const [draft, setDraft] = useState<Draft | null>(null);
    const [dragIndex, setDragIndex] = useState<number | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const [imageFailed, setImageFailed] = useState(false);
    const [pendingDelete, setPendingDelete] = useState<Zone | null>(null);

    const toPoint = (e: { clientX: number; clientY: number }): Point => {
        const rect = svgRef.current!.getBoundingClientRect();
        return [
            round(clamp((e.clientX - rect.left) / rect.width)),
            round(clamp((e.clientY - rect.top) / rect.height)),
        ];
    };

    const onCanvasClick = (e: React.MouseEvent<SVGSVGElement>) => {
        if (!draft || dragIndex !== null) {
            return;
        }
        if (draft.id === null) {
            setDraft({ ...draft, points: [...draft.points, toPoint(e)] });
        }
    };

    const onVertexDown = (index: number) => (e: ReactPointerEvent) => {
        e.stopPropagation();
        (e.target as Element).setPointerCapture(e.pointerId);
        setDragIndex(index);
    };

    const onMidpointDown = (index: number) => (e: ReactPointerEvent) => {
        if (!draft) {
            return;
        }
        e.stopPropagation();
        (e.target as Element).setPointerCapture(e.pointerId);
        const a = draft.points[index];
        const b = draft.points[(index + 1) % draft.points.length];
        const points = [...draft.points];
        points.splice(index + 1, 0, [
            round((a[0] + b[0]) / 2),
            round((a[1] + b[1]) / 2),
        ]);
        setDraft({ ...draft, points });
        setDragIndex(index + 1);
    };

    const removeVertex = (index: number) => {
        if (!draft || draft.points.length <= 3) {
            return;
        }
        setDraft({
            ...draft,
            points: draft.points.filter((_, i) => i !== index),
        });
    };

    const edgeCount = !draft
        ? 0
        : draft.points.length >= 3
          ? draft.points.length
          : draft.points.length - 1;

    const onPointerMove = (e: ReactPointerEvent<SVGSVGElement>) => {
        if (dragIndex === null || !draft) {
            return;
        }
        const points = [...draft.points];
        points[dragIndex] = toPoint(e);
        setDraft({ ...draft, points });
    };

    const save = () => {
        if (!draft) {
            return;
        }
        const payload = {
            name: draft.name,
            type: draft.type,
            points: draft.points,
        };
        const options = {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onError: (e: Record<string, string>) => setErrors(e),
            onSuccess: () => {
                setDraft(null);
                setErrors({});
            },
        };

        if (draft.id) {
            router.put(zonesRoutes.update(draft.id).url, payload, options);
        } else {
            router.post(zonesRoutes.store(sourceId).url, payload, options);
        }
    };

    const remove = (zone: Zone) => {
        setPendingDelete(zone);
    };

    const duplicate = (zone: Zone, target: string) => {
        router.post(
            zonesRoutes.duplicate(zone.id).url,
            { target_source_id: target },
            { preserveScroll: true },
        );
    };

    const visibleZones = zones.filter((z) => z.id !== draft?.id);

    return (
        <>
            <ConfirmDialog
                open={pendingDelete !== null}
                onOpenChange={(open) => !open && setPendingDelete(null)}
                title={`Deactivate zone "${pendingDelete?.name ?? ''}"?`}
                description="Past analysis results still use the zone snapshot from when they ran."
                confirmLabel="Deactivate zone"
                onConfirm={() => {
                    if (!pendingDelete) {
                        return;
                    }
                    router.delete(zonesRoutes.destroy(pendingDelete.id).url, {
                        preserveScroll: true,
                    });
                    setPendingDelete(null);
                }}
            />
        <div className="grid gap-4 lg:grid-cols-[1fr_320px]">
            <div className="relative overflow-hidden rounded-xl border bg-black">
                {imageFailed ? (
                    <div className="flex aspect-video items-center justify-center text-sm text-white/70">
                        Reference frame not available yet.
                    </div>
                ) : (
                    <img
                        src={imageUrl}
                        alt="Camera reference frame"
                        className="block w-full select-none"
                        draggable={false}
                        onError={() => setImageFailed(true)}
                    />
                )}
                <svg
                    ref={svgRef}
                    viewBox="0 0 1 1"
                    preserveAspectRatio="none"
                    className={`absolute inset-0 h-full w-full ${draft?.id === null ? 'cursor-crosshair' : ''}`}
                    onClick={onCanvasClick}
                    onPointerMove={onPointerMove}
                    onPointerUp={() => setTimeout(() => setDragIndex(null), 0)}
                    data-test="zone-canvas"
                >
                    {visibleZones.map((zone) => (
                        <g key={zone.id}>
                            <polygon
                                points={zone.points
                                    .map((p) => p.join(','))
                                    .join(' ')}
                                fill={zoneColor(zone.type)}
                                fillOpacity={0.25}
                                stroke={zoneColor(zone.type)}
                                strokeWidth={0.004}
                                vectorEffect="non-scaling-stroke"
                            />
                        </g>
                    ))}
                    {draft && draft.points.length > 0 && (
                        <g>
                            <polygon
                                points={draft.points
                                    .map((p) => p.join(','))
                                    .join(' ')}
                                fill={zoneColor(draft.type)}
                                fillOpacity={0.35}
                                stroke="#fff"
                                strokeDasharray="0.01 0.01"
                                strokeWidth={0.004}
                            />
                            {Array.from({ length: Math.max(0, edgeCount) }).map(
                                (_, i) => {
                                    const a = draft.points[i];
                                    const b =
                                        draft.points[
                                            (i + 1) % draft.points.length
                                        ];
                                    return (
                                        <circle
                                            key={`mid-${i}`}
                                            cx={(a[0] + b[0]) / 2}
                                            cy={(a[1] + b[1]) / 2}
                                            r={0.008}
                                            fill={zoneColor(draft.type)}
                                            fillOpacity={0.6}
                                            stroke="#fff"
                                            strokeWidth={0.003}
                                            className="cursor-copy"
                                            data-test="zone-midpoint"
                                            onPointerDown={onMidpointDown(i)}
                                            onClick={(e) => e.stopPropagation()}
                                        >
                                            <title>
                                                Drag to add a point
                                            </title>
                                        </circle>
                                    );
                                },
                            )}
                            {draft.points.map((p, i) => (
                                <circle
                                    key={i}
                                    cx={p[0]}
                                    cy={p[1]}
                                    r={0.012}
                                    fill="#fff"
                                    stroke={zoneColor(draft.type)}
                                    strokeWidth={0.004}
                                    className="cursor-move"
                                    data-test="zone-vertex"
                                    onPointerDown={onVertexDown(i)}
                                    onClick={(e) => e.stopPropagation()}
                                    onDoubleClick={(e) => {
                                        e.stopPropagation();
                                        removeVertex(i);
                                    }}
                                >
                                    <title>
                                        Drag to move, double-click
                                        to remove
                                    </title>
                                </circle>
                            ))}
                        </g>
                    )}
                </svg>
                {visibleZones.map((zone) => {
                    const [x, y] = zone.points[0];
                    return (
                        <span
                            key={zone.id}
                            className="pointer-events-none absolute rounded bg-black/70 px-1.5 py-0.5 text-[11px] text-white"
                            style={{ left: `${x * 100}%`, top: `${y * 100}%` }}
                        >
                            {zone.name}
                        </span>
                    );
                })}
            </div>

            <div className="space-y-4">
                {draft ? (
                    <div className="space-y-3 rounded-xl border p-4">
                        <div className="flex items-center justify-between">
                            <p className="font-medium">
                                {draft.id ? 'Edit zone' : 'New zone'}
                            </p>
                            <Button
                                variant="ghost"
                                size="icon"
                                onClick={() => {
                                    setDraft(null);
                                    setErrors({});
                                }}
                            >
                                <X />
                            </Button>
                        </div>
                        <p className="text-xs text-muted-foreground">
                            {draft.id
                                ? 'Drag white points to move, drag small mid-edge points to add points, double-click white points to remove. Shape or type changes bump the zone revision.'
                                : 'Click the image to add points (minimum 3), then refine by dragging points or mid-edge points. Follow table edges; foot position determines the zone.'}
                        </p>
                        <Field
                            label="Zone name"
                            htmlFor="zone-name"
                            error={errors.name}
                        >
                            <Input
                                id="zone-name"
                                value={draft.name}
                                onChange={(e) =>
                                    setDraft({ ...draft, name: e.target.value })
                                }
                            />
                        </Field>
                        <Field
                            label="Type"
                            htmlFor="zone-type"
                            error={errors.type}
                        >
                            <NativeSelect
                                id="zone-type"
                                value={draft.type}
                                onChange={(e) =>
                                    setDraft({
                                        ...draft,
                                        type: e.target.value as ZoneType,
                                    })
                                }
                            >
                                {ZONE_TYPES.map((t) => (
                                    <option key={t.value} value={t.value}>
                                        {t.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        <p className="text-xs text-muted-foreground">
                            Points: {draft.points.length}
                        </p>
                        {(errors.points ||
                            Object.keys(errors).some((k) =>
                                k.startsWith('points.'),
                            )) && (
                            <p className="text-sm text-destructive">
                                {errors.points ??
                                    'Invalid polygon coordinates.'}
                            </p>
                        )}
                        <div className="flex gap-2">
                            {draft.id === null && draft.points.length > 0 && (
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        setDraft({
                                            ...draft,
                                            points: draft.points.slice(0, -1),
                                        })
                                    }
                                >
                                    Remove last point
                                </Button>
                            )}
                            <Button
                                onClick={save}
                                disabled={
                                    processing ||
                                    draft.points.length < 3 ||
                                    !draft.name
                                }
                                data-test="save-zone"
                            >
                                Save zone
                            </Button>
                        </div>
                    </div>
                ) : (
                    canEdit && (
                        <Button
                            className="w-full"
                            onClick={() =>
                                setDraft({
                                    id: null,
                                    name: '',
                                    type: 'prep',
                                    points: [],
                                })
                            }
                            data-test="new-zone"
                        >
                            <Plus /> Draw new zone
                        </Button>
                    )
                )}

                <div className="space-y-2">
                    <p className="text-sm font-medium">Active zones for this source</p>
                    {zones.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            No zones yet. Without zones, analysis only records
                            people outside zones and does not assess PPE.
                        </p>
                    )}
                    {zones.map((zone) => (
                        <div
                            key={zone.id}
                            className="flex items-center gap-2 rounded-lg border p-2 text-sm"
                        >
                            <span
                                className="size-3 rounded-full"
                                style={{ background: zoneColor(zone.type) }}
                            />
                            <div className="flex-1">
                                <p className="font-medium">{zone.name}</p>
                                <p className="text-xs text-muted-foreground">
                                    {zoneLabel(zone.type)} · rev{' '}
                                    {zone.revision}
                                </p>
                            </div>
                            {canEdit && (
                                <>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        title="Edit"
                                        onClick={() =>
                                            setDraft({
                                                id: zone.id,
                                                name: zone.name,
                                                type: zone.type,
                                                points: zone.points,
                                            })
                                        }
                                    >
                                        <Pencil />
                                    </Button>
                                    {otherSources.length > 0 && (
                                        <NativeSelect
                                            className="h-8 w-9 px-1"
                                            title="Copy to another source"
                                            value=""
                                            onChange={(e) =>
                                                e.target.value &&
                                                duplicate(zone, e.target.value)
                                            }
                                        >
                                            <option value="">⧉</option>
                                            {otherSources.map((s) => (
                                                <option key={s.id} value={s.id}>
                                                    Copy to {s.name}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                    )}
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        title="Deactivate"
                                        onClick={() => remove(zone)}
                                    >
                                        <Trash2 />
                                    </Button>
                                </>
                            )}
                        </div>
                    ))}
                </div>
                {otherSources.length > 0 && canEdit && (
                    <p className="flex items-center gap-1 text-xs text-muted-foreground">
                        <Copy className="size-3" /> Zones can be copied to another
                        source and adjusted for the camera angle.
                    </p>
                )}
            </div>
        </div>
        </>
    );
}
