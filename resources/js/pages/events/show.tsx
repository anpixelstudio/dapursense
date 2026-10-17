import { Head, Link, router, useForm } from '@inertiajs/react';
import { Lock, LockOpen } from 'lucide-react';
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
    PENDING: 'Bukti masih diproses worker.',
    FAILED: 'Bukti gagal dibuat.',
    EXPIRED: 'Bukti sudah dihapus sesuai retensi.',
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
    const form = useForm({
        to_status: allowedTransitions[0]?.value ?? '',
        expected_revision: event.revision,
        reason_code: '',
        reason: '',
        action_note: '',
    });

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
                    title={eventTypeLabel(event.type)}
                    description={
                        isDevice
                            ? `${eventPlace(event)}${event.evidence.host ? ` · ${event.evidence.host as string}:${event.evidence.port as number}` : ''} · ${formatDateTime(event.occurred_at ?? event.created_at, tenant?.timezone)}`
                            : `${event.source?.name ?? ''} · ${event.run?.video?.original_name ?? ''} · posisi ${formatMs(event.first_ms)}–${formatMs(event.last_ms)}`
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
                        Event ini berasal dari simulator dan bukan deteksi
                        nyata.
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
                                    Detail perangkat
                                </p>
                                <dl className="grid grid-cols-[160px_1fr] gap-y-1 text-muted-foreground">
                                    <dt>Perangkat</dt>
                                    <dd className="text-foreground">
                                        {eventPlace(event)}
                                    </dd>
                                    <dt>Alamat</dt>
                                    <dd className="text-foreground">
                                        {event.evidence.host
                                            ? `${event.evidence.host as string}:${event.evidence.port as number}`
                                            : 'heartbeat / komponen server'}
                                    </dd>
                                    <dt>Penyebab</dt>
                                    <dd className="text-foreground">
                                        {deviceErrorLabel(
                                            event.evidence.error as
                                                | string
                                                | null,
                                        )}
                                    </dd>
                                    <dt>Gagal berturut-turut</dt>
                                    <dd className="text-foreground">
                                        {event.evidence.failures as number} kali
                                    </dd>
                                    <dt>Mulai offline</dt>
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
                                    Buka Uptime Perangkat
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
                                                alt="Snapshot kejadian"
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
                                        Klip bukti
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
                                    Detail deteksi
                                </p>
                                <dl className="grid grid-cols-[160px_1fr] gap-y-1 text-muted-foreground">
                                    <dt>Orang</dt>
                                    <dd className="text-foreground">
                                        {event.track
                                            ? `Orang ${event.track.track_key}`
                                            : '-'}
                                    </dd>
                                    {event.evidence.items && (
                                        <>
                                            <dt>APD</dt>
                                            <dd className="text-foreground">
                                                {event.evidence.items
                                                    .map(ppeLabel)
                                                    .join(', ')}
                                            </dd>
                                        </>
                                    )}
                                    {event.evidence.zone_name && (
                                        <>
                                            <dt>Zona</dt>
                                            <dd className="text-foreground">
                                                {event.evidence.zone_name}
                                            </dd>
                                        </>
                                    )}
                                    <dt>Skor tertinggi</dt>
                                    <dd className="text-foreground">
                                        {formatPercent(event.peak_score)}
                                    </dd>
                                    <dt>Status siklus</dt>
                                    <dd className="text-foreground">
                                        {lifecycleLabel(event.lifecycle)}
                                    </dd>
                                    <dt>Waktu kejadian</dt>
                                    <dd className="text-foreground">
                                        {event.occurred_at
                                            ? formatDateTime(
                                                  event.occurred_at,
                                                  tenant?.timezone,
                                              )
                                            : 'Waktu rekaman tidak diketahui'}
                                    </dd>
                                    <dt>Model</dt>
                                    <dd className="text-foreground">
                                        {event.run?.model?.name}{' '}
                                        {event.run?.model?.version}
                                    </dd>
                                    <dt>Aturan</dt>
                                    <dd className="text-foreground">
                                        {ruleRevisionLabel(
                                            event.run?.rule_revision,
                                        )}
                                    </dd>
                                    <dt>Versi aplikasi</dt>
                                    <dd className="text-foreground">
                                        {event.run?.app_version}
                                    </dd>
                                </dl>
                                {event.run && (
                                    <Link
                                        href={analyses.show(event.run.id)}
                                        className="mt-3 inline-block text-sm hover:underline"
                                    >
                                        Buka timeline analisis
                                    </Link>
                                )}
                            </div>
                        )}

                        {ppe.length > 0 && (
                            <div className="rounded-xl border p-4 text-sm">
                                <p className="mb-2 font-medium">
                                    Penilaian APD orang ini
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
                                                    ? 'ada'
                                                    : p.state === 'missing'
                                                      ? 'tidak terdeteksi'
                                                      : `tidak dapat dinilai${p.reason ? ` (${ppeReasonLabel(p.reason)})` : ''}`}
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
                        {canReview && allowedTransitions.length > 0 && (
                            <form
                                onSubmit={submit}
                                className="space-y-3 rounded-xl border p-4"
                            >
                                <p className="font-medium">Keputusan review</p>
                                {form.errors.expected_revision && (
                                    <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-100">
                                        {form.errors.expected_revision}
                                    </p>
                                )}
                                <Field
                                    label="Ubah status ke"
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
                                        label="Kode alasan"
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
                                                Pilih alasan
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
                                                ? 'Catatan (opsional)'
                                                : 'Alasan'
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
                                        label="Tindakan penanganan"
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
                                    Simpan keputusan
                                </Button>
                                <p className="text-xs text-muted-foreground">
                                    Revisi event: {event.revision}. Jika
                                    reviewer lain mengubah lebih dulu, Anda akan
                                    diminta memeriksa ulang.
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
                                        <LockOpen /> Lepas penahanan bukti
                                    </>
                                ) : (
                                    <>
                                        <Lock /> Tahan bukti dari penghapusan
                                    </>
                                )}
                            </Button>
                        )}
                        {event.evidence_hold && (
                            <p className="text-xs text-muted-foreground">
                                Bukti ditahan dan tidak dihapus oleh retensi
                                otomatis.
                            </p>
                        )}

                        <div className="rounded-xl border p-4 text-sm">
                            <p className="mb-2 font-medium">Riwayat review</p>
                            {(event.reviews ?? []).length === 0 ? (
                                <p className="text-muted-foreground">
                                    Belum ada keputusan.
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
                                                    {r.actor?.name ?? 'Sistem'}
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
                                                    Tindakan: {r.action_note}
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
    breadcrumbs: [{ title: 'Anomali', href: events.index() }],
};
