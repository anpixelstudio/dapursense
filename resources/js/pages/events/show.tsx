import { Head, Link, router, useForm } from '@inertiajs/react';
import { Lock, LockOpen, Sparkles } from 'lucide-react';
import { useState } from 'react';
import {
    CategoryBadge,
    Field,
    NativeSelect,
    Page,
    PageHeader,
    ReviewBadge,
    SeverityBadge,
    SimulationBadge,
    Textarea,
} from '@/components/kitchen/ui';
import { Button } from '@/components/ui/button';
import { AssistantMarkdown } from '@/components/assistant-markdown';
import { postAssistant } from '@/lib/assistant';
import {
    DISMISS_LABELS,
    deviceErrorLabel,
    eventPlace,
    eventTypeLabel,
    formatDateTime,
    formatMs,
    formatPercent,
    lifecycleLabel,
    ppeLabel,
    ppeReasonLabel,
    REVIEW_LABELS,
    ruleRevisionLabel,
    useTenant,
} from '@/lib/kitchen';
import analyses from '@/routes/analyses';
import assistant from '@/routes/assistant';
import events from '@/routes/events';
import uptime from '@/routes/uptime';
import type {
    AnomalyEvent,
    PpeAssessment,
    ReviewStatus,
    Zone,
} from '@/types/kitchen';

type Props = {
    event: AnomalyEvent;
    ppe: PpeAssessment[];
    zones: Zone[];
    dismissReasons: string[];
    allowedTransitions: { value: ReviewStatus; label: string }[];
};

const MEDIA_ERRORS: Record<string, string> = {
    PENDING: 'Evidence is still being processed.',
    FAILED: 'Evidence generation failed.',
    EXPIRED: 'Evidence removed per retention policy.',
};

export default function EventShow({
    event,
    ppe,
    zones,
    dismissReasons,
    allowedTransitions,
}: Props) {
    const { can, tenant } = useTenant();
    const isDevice = event.type === 'device_offline';
    const [summary, setSummary] = useState<string | null>(null);
    const [summaryBusy, setSummaryBusy] = useState(false);
    const [summaryError, setSummaryError] = useState<string | null>(null);
    const form = useForm({
        to_status: allowedTransitions[0]?.value ?? '',
        expected_revision: event.revision,
        reason_code: '',
        reason: '',
        action_note: '',
    });

    const summarize = async () => {
        setSummaryError(null);
        setSummaryBusy(true);
        try {
            const data = await postAssistant<{ text: string }>(
                assistant.summarize(event.id).url,
                { locale: 'en' },
            );
            setSummary(data.text);
        } catch (e) {
            setSummaryError(e instanceof Error ? e.message : 'Summarization failed');
        } finally {
            setSummaryBusy(false);
        }
    };

    const canReview = allowedTransitions.some((t) =>
        t.value === 'OPEN' ? can('reopen_events') : can('review_events'),
    );
    const snapshot = event.media?.find((m) => m.kind === 'snapshot');
    const clip = event.media?.find((m) => m.kind === 'clip');
    const bbox = event.evidence.bbox_xyxy_normalized;
    const zoneId = event.evidence.zone_id as string | undefined;
    const zone = zones.find((z) => z.id === zoneId);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            expected_revision: event.revision,
        }));
        form.patch(events.review(event.id).url, {
            preserveScroll: true,
            onSuccess: () => form.reset('reason', 'reason_code', 'action_note'),
        });
    };

    const mediaUrl = (id: string) =>
        events.media({ event: event.id, media: id }).url;

    return (
        <>
            <Head title={eventTypeLabel(event.type)} />
            <Page>
                <PageHeader
                    brand
                    title={eventTypeLabel(event.type)}
                    description={
                        isDevice
                            ? `${eventPlace(event)}${event.evidence.host ? ` · ${event.evidence.host as string}:${event.evidence.port as number}` : ''} · ${formatDateTime(event.occurred_at ?? event.created_at, tenant?.timezone)}`
                            : `${event.source?.name ?? ''} · ${event.run?.video?.original_name ?? ''} · position ${formatMs(event.first_ms)}–${formatMs(event.last_ms)}`
                    }
                    actions={
                        <>
                            {event.is_simulation && <SimulationBadge />}
                            <CategoryBadge category={event.category} />
                            {event.category === 'violation' && (
                                <SeverityBadge severity={event.severity} />
                            )}
                            <ReviewBadge status={event.review_status} />
                        </>
                    }
                />

                {event.is_simulation && (
                    <p className="rounded-lg border border-violet-300 bg-violet-50 px-4 py-2 text-sm text-violet-900 dark:bg-violet-950 dark:text-violet-100">
                        This event came from the simulator, not real
                        detection.
                    </p>
                )}

                <div className="grid gap-4 lg:grid-cols-[1fr_380px]">
                    <div className="space-y-4">
                        {isDevice && (
                            <div
                                className="rounded-xl border p-4 text-sm"
                                data-test="device-detail"
                            >
                                <p className="mb-2 font-medium">
                                    Device details
                                </p>
                                <dl className="grid grid-cols-[160px_1fr] gap-y-1 text-muted-foreground">
                                    <dt>Device</dt>
                                    <dd className="text-foreground">
                                        {eventPlace(event)}
                                    </dd>
                                    <dt>Address</dt>
                                    <dd className="text-foreground">
                                        {event.evidence.host
                                            ? `${event.evidence.host as string}:${event.evidence.port as number}`
                                            : 'heartbeat / server component'}
                                    </dd>
                                    <dt>Reason</dt>
                                    <dd className="text-foreground">
                                        {deviceErrorLabel(
                                            event.evidence.error as
                                                | string
                                                | null,
                                        )}
                                    </dd>
                                    <dt>Consecutive failures</dt>
                                    <dd className="text-foreground">
                                        {event.evidence.failures as number}{' '}
                                        times
                                    </dd>
                                    <dt>Offline since</dt>
                                    <dd className="text-foreground">
                                        {formatDateTime(
                                            event.occurred_at,
                                            tenant?.timezone,
                                        )}
                                    </dd>
                                </dl>
                                <Link
                                    href={uptime.index()}
                                    className="mt-3 inline-block text-sm hover:underline"
                                >
                                    Open Device Uptime
                                </Link>
                            </div>
                        )}
                        {!isDevice && (
                            <div className="grid gap-4 md:grid-cols-2">
                                <div className="space-y-1">
                                    <p className="text-sm font-medium">
                                        Snapshot
                                    </p>
                                    {snapshot?.state === 'READY' ? (
                                        <div className="relative overflow-hidden rounded-xl bg-black">
                                            <img
                                                src={mediaUrl(snapshot.id)}
                                                alt="Event snapshot"
                                                className="block w-full"
                                            />
                                            <svg
                                                viewBox="0 0 1 1"
                                                preserveAspectRatio="none"
                                                className="pointer-events-none absolute inset-0 h-full w-full"
                                            >
                                                {zone && (
                                                    <polygon
                                                        points={zone.points
                                                            .map((p) =>
                                                                p.join(','),
                                                            )
                                                            .join(' ')}
                                                        fill="#dc2626"
                                                        fillOpacity={0.15}
                                                        stroke="#dc2626"
                                                        strokeWidth={0.004}
                                                    />
                                                )}
                                                {bbox && (
                                                    <rect
                                                        x={bbox[0]}
                                                        y={bbox[1]}
                                                        width={
                                                            bbox[2] - bbox[0]
                                                        }
                                                        height={
                                                            bbox[3] - bbox[1]
                                                        }
                                                        fill="none"
                                                        stroke="#ef4444"
                                                        strokeWidth={0.006}
                                                    />
                                                )}
                                            </svg>
                                        </div>
                                    ) : (
                                        <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                                            {
                                                MEDIA_ERRORS[
                                                    snapshot?.state ?? 'FAILED'
                                                ]
                                            }{' '}
                                            {snapshot?.error_code
                                                ? `(${snapshot.error_code})`
                                                : ''}
                                        </p>
                                    )}
                                </div>
                                <div className="space-y-1">
                                    <p className="text-sm font-medium">
                                        Evidence clip
                                    </p>
                                    {clip?.state === 'READY' ? (
                                        <video
                                            src={mediaUrl(clip.id)}
                                            controls
                                            playsInline
                                            className="w-full rounded-xl bg-black"
                                        />
                                    ) : (
                                        <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                                            {
                                                MEDIA_ERRORS[
                                                    clip?.state ?? 'FAILED'
                                                ]
                                            }{' '}
                                            {clip?.error_code
                                                ? `(${clip.error_code})`
                                                : ''}
                                        </p>
                                    )}
                                </div>
                            </div>
                        )}

                        {!isDevice && (
                            <div className="rounded-xl border p-4 text-sm">
                                <p className="mb-2 font-medium">
                                    Detection details
                                </p>
                                <dl className="grid grid-cols-[160px_1fr] gap-y-1 text-muted-foreground">
                                    <dt>Person</dt>
                                    <dd className="text-foreground">
                                        {event.track
                                            ? `Person ${event.track.track_key}`
                                            : '-'}
                                    </dd>
                                    {event.evidence.items && (
                                        <>
                                            <dt>PPE</dt>
                                            <dd className="text-foreground">
                                                {event.evidence.items
                                                    .map(ppeLabel)
                                                    .join(', ')}
                                            </dd>
                                        </>
                                    )}
                                    {event.evidence.zone_name && (
                                        <>
                                            <dt>Zone</dt>
                                            <dd className="text-foreground">
                                                {event.evidence.zone_name}
                                            </dd>
                                        </>
                                    )}
                                    <dt>Peak score</dt>
                                    <dd className="text-foreground">
                                        {formatPercent(event.peak_score)}
                                    </dd>
                                    <dt>Lifecycle status</dt>
                                    <dd className="text-foreground">
                                        {lifecycleLabel(event.lifecycle)}
                                    </dd>
                                    <dt>Event time</dt>
                                    <dd className="text-foreground">
                                        {event.occurred_at
                                            ? formatDateTime(
                                                  event.occurred_at,
                                                  tenant?.timezone,
                                              )
                                            : 'Recording time unknown'}
                                    </dd>
                                    <dt>Model</dt>
                                    <dd className="text-foreground">
                                        {event.run?.model?.name}{' '}
                                        {event.run?.model?.version}
                                    </dd>
                                    <dt>Rules</dt>
                                    <dd className="text-foreground">
                                        {ruleRevisionLabel(
                                            event.run?.rule_revision,
                                        )}
                                    </dd>
                                    <dt>App version</dt>
                                    <dd className="text-foreground">
                                        {event.run?.app_version}
                                    </dd>
                                </dl>
                                {event.run && (
                                    <Link
                                        href={analyses.show(event.run.id)}
                                        className="mt-3 inline-block text-sm hover:underline"
                                    >
                                        Open analysis timeline
                                    </Link>
                                )}
                            </div>
                        )}

                        {ppe.length > 0 && (
                            <div className="rounded-xl border p-4 text-sm">
                                <p className="mb-2 font-medium">
                                    PPE assessment for this person
                                </p>
                                <ul className="space-y-1">
                                    {ppe.map((p) => (
                                        <li
                                            key={p.id}
                                            className="flex justify-between gap-2"
                                        >
                                            <span>
                                                {ppeLabel(p.item_code)}:{' '}
                                                {p.state === 'present'
                                                    ? 'present'
                                                    : p.state === 'missing'
                                                      ? 'not detected'
                                                      : `cannot assess${p.reason ? ` (${ppeReasonLabel(p.reason)})` : ''}`}
                                            </span>
                                            <span className="font-mono text-muted-foreground">
                                                {formatMs(p.start_ms)}–
                                                {formatMs(p.end_ms)}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </div>

                    <div className="space-y-4">
                        <div
                            className="space-y-3 rounded-xl border p-4"
                            data-test="ai-summary"
                        >
                            <div className="space-y-1">
                                <p className="flex items-center gap-2 text-sm font-medium">
                                    <Sparkles className="size-4 text-primary" />
                                    Summarize with AI
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Short owner-ready narrative from this event
                                    — locked summarizer prompt.
                                </p>
                            </div>
                            <Button
                                type="button"
                                className="w-full"
                                disabled={summaryBusy}
                                onClick={() => void summarize()}
                                data-test="ai-summarize"
                            >
                                <Sparkles className="size-4" />
                                {summaryBusy ? 'Thinking…' : 'Summarize with AI'}
                            </Button>
                            {summaryError && (
                                <p className="text-sm text-destructive">
                                    {summaryError}
                                </p>
                            )}
                            {summary ? (
                                <div className="rounded-lg border bg-card p-3 shadow-xs">
                                    <AssistantMarkdown content={summary} />
                                </div>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    Summary appears here after you run it.
                                </p>
                            )}
                        </div>

                        {canReview && allowedTransitions.length > 0 && (
                            <form
                                onSubmit={submit}
                                className="space-y-3 rounded-xl border p-4"
                            >
                                <p className="font-medium">Review decision</p>
                                {form.errors.expected_revision && (
                                    <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-100">
                                        {form.errors.expected_revision}
                                    </p>
                                )}
                                <Field
                                    label="Change status to"
                                    htmlFor="review-status"
                                    error={form.errors.to_status}
                                >
                                    <NativeSelect
                                        id="review-status"
                                        value={form.data.to_status}
                                        onChange={(e) =>
                                            form.setData(
                                                'to_status',
                                                e.target.value as ReviewStatus,
                                            )
                                        }
                                    >
                                        {allowedTransitions.map((t) => (
                                            <option
                                                key={t.value}
                                                value={t.value}
                                            >
                                                {t.label}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                                {form.data.to_status === 'DISMISSED' && (
                                    <Field
                                        label="Reason code"
                                        htmlFor="review-code"
                                        error={form.errors.reason_code}
                                    >
                                        <NativeSelect
                                            id="review-code"
                                            value={form.data.reason_code}
                                            onChange={(e) =>
                                                form.setData(
                                                    'reason_code',
                                                    e.target.value,
                                                )
                                            }
                                        >
                                            <option value="">
                                                Select a reason
                                            </option>
                                            {dismissReasons.map((r) => (
                                                <option key={r} value={r}>
                                                    {DISMISS_LABELS[r] ?? r}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                    </Field>
                                )}
                                {form.data.to_status !== 'RESOLVED' && (
                                    <Field
                                        label={
                                            form.data.to_status ===
                                            'ACKNOWLEDGED'
                                                ? 'Notes (optional)'
                                                : 'Reason'
                                        }
                                        htmlFor="review-reason"
                                        error={form.errors.reason}
                                    >
                                        <Textarea
                                            id="review-reason"
                                            value={form.data.reason}
                                            onChange={(e) =>
                                                form.setData(
                                                    'reason',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                )}
                                {form.data.to_status === 'RESOLVED' && (
                                    <Field
                                        label="Resolution action"
                                        htmlFor="review-action"
                                        error={form.errors.action_note}
                                    >
                                        <Textarea
                                            id="review-action"
                                            value={form.data.action_note}
                                            onChange={(e) =>
                                                form.setData(
                                                    'action_note',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                )}
                                <Button
                                    disabled={form.processing}
                                    className="w-full"
                                    data-test="submit-review"
                                >
                                    Save decision
                                </Button>
                                <p className="text-xs text-muted-foreground">
                                    Event revision: {event.revision}. If
                                    another reviewer changed it first, you will be
                                    asked to review again.
                                </p>
                            </form>
                        )}

                        {can('hold_evidence') && !isDevice && (
                            <Button
                                variant="outline"
                                className="w-full"
                                onClick={() =>
                                    router.post(
                                        events.hold(event.id).url,
                                        { hold: !event.evidence_hold },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                {event.evidence_hold ? (
                                    <>
                                        <LockOpen /> Release evidence hold
                                    </>
                                ) : (
                                    <>
                                        <Lock /> Hold evidence from deletion
                                    </>
                                )}
                            </Button>
                        )}
                        {event.evidence_hold && (
                            <p className="text-xs text-muted-foreground">
                                Evidence is held and not removed by retention
                                policy.
                            </p>
                        )}

                        <div className="rounded-xl border p-4 text-sm">
                            <p className="mb-2 font-medium">Review history</p>
                            {(event.reviews ?? []).length === 0 ? (
                                <p className="text-muted-foreground">
                                    No decisions yet.
                                </p>
                            ) : (
                                <ol className="space-y-3">
                                    {event.reviews!.map((r) => (
                                        <li
                                            key={r.id}
                                            className="border-l-2 pl-3"
                                        >
                                            <p>
                                                <span className="font-medium">
                                                    {r.actor?.name ?? 'System'}
                                                </span>
                                                : {REVIEW_LABELS[r.from_status]}{' '}
                                                → {REVIEW_LABELS[r.to_status]}
                                            </p>
                                            {r.reason_code && (
                                                <p className="text-xs text-muted-foreground">
                                                    {DISMISS_LABELS[
                                                        r.reason_code
                                                    ] ?? r.reason_code}
                                                </p>
                                            )}
                                            {r.reason && (
                                                <p className="text-muted-foreground">
                                                    {r.reason}
                                                </p>
                                            )}
                                            {r.action_note && (
                                                <p className="text-muted-foreground">
                                                    Action: {r.action_note}
                                                </p>
                                            )}
                                            <p className="text-xs text-muted-foreground">
                                                {formatDateTime(
                                                    r.created_at,
                                                    tenant?.timezone,
                                                )}
                                            </p>
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </div>
                    </div>
                </div>
            </Page>
        </>
    );
}

EventShow.layout = {
    breadcrumbs: [{ title: 'Anomalies', href: events.index() }],
};
