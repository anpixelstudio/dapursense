import { Head, router, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { ConfirmDialog } from '@/components/kitchen/confirm-dialog';
import {
    EmptyState,
    Field,
    NativeSelect,
    Page,
    PageHeader,
    Pill,
    Table,
    Td,
    Th,
} from '@/components/kitchen/ui';
import type { Tone } from '@/components/kitchen/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDateTime, useTenant } from '@/lib/kitchen';
import reportSchedules from '@/routes/report-schedules';
import reports from '@/routes/reports';

type Report = {
    id: string;
    format: 'csv' | 'pdf';
    status: 'QUEUED' | 'RUNNING' | 'READY' | 'FAILED' | 'EXPIRED';
    filters: Record<string, string | boolean | null>;
    error_code: string | null;
    created_at: string;
    completed_at: string | null;
    expires_at: string | null;
    requester?: { id: number; name: string } | null;
};

type Schedule = {
    id: string;
    frequency: 'daily' | 'weekly';
    format: 'csv' | 'pdf';
    timezone: string;
    filters: Record<string, unknown>;
    next_run_at: string;
    enabled: boolean;
};

type Props = {
    reports: Report[];
    schedules: Schedule[];
    options: {
        properties: { id: string; name: string }[];
        kitchens: { id: string; name: string; property_id: string }[];
        sources: { id: string; name: string }[];
        review_statuses: { value: string; label: string }[];
    };
};

const STATUS: Record<Report['status'], [string, Tone]> = {
    QUEUED: ['Queued', 'neutral'],
    RUNNING: ['Running', 'info'],
    READY: ['Ready', 'success'],
    FAILED: ['Failed', 'danger'],
    EXPIRED: ['Expired', 'neutral'],
};

const today = () => new Date().toISOString().slice(0, 10);
const weekAgo = () =>
    new Date(Date.now() - 6 * 86400000).toISOString().slice(0, 10);

export default function Reports({ reports: list, schedules, options }: Props) {
    const { can, tenant } = useTenant();
    const [pendingSchedule, setPendingSchedule] = useState<Schedule | null>(
        null,
    );
    const form = useForm({
        format: 'csv',
        from_date: weekAgo(),
        to_date: today(),
        property_id: '',
        kitchen_id: '',
        source_id: '',
        review_status: '',
        include_simulation: false,
    });
    const scheduleForm = useForm({
        frequency: 'daily',
        format: 'pdf',
        include_simulation: false,
    });

    const pending = list.some(
        (r) => r.status === 'QUEUED' || r.status === 'RUNNING',
    );

    useEffect(() => {
        if (!pending) {
            return;
        }
        const timer = setInterval(
            () => router.reload({ only: ['reports'] }),
            3000,
        );

        return () => clearInterval(timer);
    }, [pending]);

    return (
        <>
            <Head title="Reports" />
            <ConfirmDialog
                open={pendingSchedule !== null}
                onOpenChange={(open) => !open && setPendingSchedule(null)}
                title="Delete this report schedule?"
                description={`${pendingSchedule?.frequency === 'weekly' ? 'Weekly' : 'Daily'} ${pendingSchedule?.format.toUpperCase() ?? ''} reports will no longer be generated automatically.`}
                confirmLabel="Delete schedule"
                onConfirm={() => {
                    if (!pendingSchedule) {
                        return;
                    }
                    router.delete(
                        reportSchedules.destroy(pendingSchedule.id).url,
                        { preserveScroll: true },
                    );
                    setPendingSchedule(null);
                }}
            />
            <Page>
                <PageHeader
                    title="Reports"
                    description={`CSV/PDF reports are built in the background with data limits at request time. Dates follow the organization timezone (${tenant?.timezone}).`}
                />

                {can('generate_reports') && (
                    <form
                        className="space-y-4 rounded-xl border bg-card p-4 shadow-xs"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.transform(
                                (data) =>
                                    Object.fromEntries(
                                        Object.entries(data).filter(
                                            ([, v]) => v !== '',
                                        ),
                                    ) as typeof data,
                            );
                            form.post(reports.store().url, {
                                preserveScroll: true,
                            });
                        }}
                    >
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 items-start">
                        <Field
                            label="From date"
                            htmlFor="report-from"
                            error={form.errors.from_date}
                        >
                            <Input
                                id="report-from"
                                type="date"
                                value={form.data.from_date}
                                onChange={(e) =>
                                    form.setData('from_date', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="To date"
                            htmlFor="report-to"
                            error={form.errors.to_date}
                        >
                            <Input
                                id="report-to"
                                type="date"
                                value={form.data.to_date}
                                onChange={(e) =>
                                    form.setData('to_date', e.target.value)
                                }
                            />
                        </Field>
                        <Field label="Property" htmlFor="report-property">
                            <NativeSelect
                                id="report-property"
                                value={form.data.property_id}
                                onChange={(e) =>
                                    form.setData({
                                        ...form.data,
                                        property_id: e.target.value,
                                        kitchen_id: '',
                                    })
                                }
                            >
                                <option value="">All</option>
                                {options.properties.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        <Field label="Kitchen" htmlFor="report-kitchen">
                            <NativeSelect
                                id="report-kitchen"
                                value={form.data.kitchen_id}
                                onChange={(e) =>
                                    form.setData('kitchen_id', e.target.value)
                                }
                            >
                                <option value="">All</option>
                                {options.kitchens
                                    .filter(
                                        (k) =>
                                            !form.data.property_id ||
                                            k.property_id ===
                                                form.data.property_id,
                                    )
                                    .map((k) => (
                                        <option key={k.id} value={k.id}>
                                            {k.name}
                                        </option>
                                    ))}
                            </NativeSelect>
                        </Field>
                        <Field label="Source" htmlFor="report-source">
                            <NativeSelect
                                id="report-source"
                                value={form.data.source_id}
                                onChange={(e) =>
                                    form.setData('source_id', e.target.value)
                                }
                            >
                                <option value="">All</option>
                                {options.sources.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        <Field label="Review status" htmlFor="report-status">
                            <NativeSelect
                                id="report-status"
                                value={form.data.review_status}
                                onChange={(e) =>
                                    form.setData(
                                        'review_status',
                                        e.target.value,
                                    )
                                }
                            >
                                <option value="">All</option>
                                {options.review_statuses.map((s) => (
                                    <option key={s.value} value={s.value}>
                                        {s.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        <Field label="Format" htmlFor="report-format">
                            <NativeSelect
                                id="report-format"
                                value={form.data.format}
                                onChange={(e) =>
                                    form.setData('format', e.target.value)
                                }
                            >
                                <option value="csv">CSV</option>
                                <option value="pdf">PDF</option>
                            </NativeSelect>
                        </Field>
                        </div>
                        <div className="flex flex-col gap-3 border-t pt-4 sm:flex-row sm:items-center sm:justify-between">
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={form.data.include_simulation}
                                    onChange={(e) =>
                                        form.setData(
                                            'include_simulation',
                                            e.target.checked,
                                        )
                                    }
                                />
                                Include simulation results
                            </label>
                            <Button
                                className="h-9 shrink-0 sm:min-w-36"
                                disabled={form.processing}
                                data-test="generate-report"
                            >
                                Generate report
                            </Button>
                        </div>
                    </form>
                )}

                {list.length === 0 ? (
                    <EmptyState title="No reports yet" />
                ) : (
                    <Table>
                        <thead>
                            <tr>
                                <Th>Created</Th>
                                <Th>Period</Th>
                                <Th>Format</Th>
                                <Th>Status</Th>
                                <Th>By</Th>
                                <Th />
                            </tr>
                        </thead>
                        <tbody>
                            {list.map((r) => (
                                <tr key={r.id}>
                                    <Td className="whitespace-nowrap">
                                        {formatDateTime(
                                            r.created_at,
                                            tenant?.timezone,
                                        )}
                                    </Td>
                                    <Td>
                                        {String(r.filters.from_date)} –{' '}
                                        {String(r.filters.to_date)}
                                        {r.filters.include_simulation ? (
                                            <span className="ml-2 text-xs text-violet-700">
                                                + simulation
                                            </span>
                                        ) : null}
                                    </Td>
                                    <Td className="uppercase">{r.format}</Td>
                                    <Td>
                                        <Pill tone={STATUS[r.status][1]}>
                                            {STATUS[r.status][0]}
                                        </Pill>
                                        {r.error_code && (
                                            <p className="text-xs text-destructive">
                                                {r.error_code}
                                            </p>
                                        )}
                                    </Td>
                                    <Td>{r.requester?.name ?? 'Schedule'}</Td>
                                    <Td className="text-right">
                                        {r.status === 'READY' &&
                                            can('download_reports') && (
                                                <Button
                                                    asChild
                                                    size="sm"
                                                    variant="outline"
                                                >
                                                    <a
                                                        href={
                                                            reports.download(
                                                                r.id,
                                                            ).url
                                                        }
                                                    >
                                                        Download
                                                    </a>
                                                </Button>
                                            )}
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}

                <section className="space-y-3">
                    <h2 className="font-medium">Scheduled reports</h2>
                    {can('generate_reports') && (
                        <form
                            className="space-y-4 rounded-xl border bg-card p-4 shadow-xs"
                            onSubmit={(e) => {
                                e.preventDefault();
                                scheduleForm.post(reportSchedules.store().url, {
                                    preserveScroll: true,
                                });
                            }}
                        >
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 items-start">
                                <Field
                                    label="Frequency"
                                    htmlFor="schedule-frequency"
                                >
                                    <NativeSelect
                                        id="schedule-frequency"
                                        value={scheduleForm.data.frequency}
                                        onChange={(e) =>
                                            scheduleForm.setData(
                                                'frequency',
                                                e.target.value,
                                            )
                                        }
                                    >
                                        <option value="daily">Daily</option>
                                        <option value="weekly">Weekly</option>
                                    </NativeSelect>
                                </Field>
                                <Field label="Format" htmlFor="schedule-format">
                                    <NativeSelect
                                        id="schedule-format"
                                        value={scheduleForm.data.format}
                                        onChange={(e) =>
                                            scheduleForm.setData(
                                                'format',
                                                e.target.value,
                                            )
                                        }
                                    >
                                        <option value="csv">CSV</option>
                                        <option value="pdf">PDF</option>
                                    </NativeSelect>
                                </Field>
                            </div>
                            <div className="flex flex-col gap-3 border-t pt-4 sm:flex-row sm:items-center sm:justify-between">
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={
                                            scheduleForm.data.include_simulation
                                        }
                                        onChange={(e) =>
                                            scheduleForm.setData(
                                                'include_simulation',
                                                e.target.checked,
                                            )
                                        }
                                    />
                                    Include simulation
                                </label>
                                <Button
                                    className="h-9 shrink-0"
                                    disabled={scheduleForm.processing}
                                >
                                    Add schedule
                                </Button>
                            </div>
                        </form>
                    )}
                    {schedules.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No schedules yet.
                        </p>
                    ) : (
                        <ul className="divide-y rounded-xl border">
                            {schedules.map((s) => (
                                <li
                                    key={s.id}
                                    className="flex items-center gap-3 px-4 py-2 text-sm"
                                >
                                    <span className="font-medium">
                                        {s.frequency === 'daily'
                                            ? 'Daily'
                                            : 'Weekly'}{' '}
                                        · {s.format.toUpperCase()}
                                    </span>
                                    <span className="text-muted-foreground">
                                        next{' '}
                                        {formatDateTime(
                                            s.next_run_at,
                                            s.timezone,
                                        )}{' '}
                                        ({s.timezone})
                                    </span>
                                    {can('generate_reports') && (
                                        <Button
                                            className="ml-auto"
                                            variant="ghost"
                                            size="icon"
                                            title="Delete schedule"
                                            onClick={() =>
                                                setPendingSchedule(s)
                                            }
                                        >
                                            <Trash2 />
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </Page>
        </>
    );
}

Reports.layout = {
    breadcrumbs: [{ title: 'Reports', href: reports.index() }],
};
