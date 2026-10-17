import { Head, Link, router, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import {
    EmptyState,
    Field,
    NativeSelect,
    Page,
    PageErrors,
    PageHeader,
    Pagination,
    Table,
    Td,
    Th,
    VideoStatusBadge,
} from '@/components/kitchen/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    formatBytes,
    formatDateTime,
    formatMs,
    useTenant,
} from '@/lib/kitchen';
import { cn } from '@/lib/utils';
import sources from '@/routes/sources';
import videos from '@/routes/videos';
import type { Paginated, Video } from '@/types/kitchen';

type Props = {
    videos: Paginated<Video>;
    filters: { status?: string; source_id?: string; search?: string };
    sources: {
        id: string;
        name: string;
        kitchen?: { id: string; name: string };
    }[];
    limits: {
        max_bytes: number;
        max_duration_minutes: number;
        extensions: string[];
    };
};

export default function Videos({
    videos: page,
    filters,
    sources: sourceList,
    limits,
}: Props) {
    const { can, tenant } = useTenant();
    const [search, setSearch] = useState(filters.search ?? '');
    const fileRef = useRef<HTMLInputElement>(null);
    const form = useForm<{
        source_id: string;
        video: File | null;
        recorded_at: string;
        recorded_timezone: string;
    }>({
        source_id: sourceList[0]?.id ?? '',
        video: null,
        recorded_at: '',
        recorded_timezone: tenant?.timezone ?? 'Asia/Jakarta',
    });

    const applyFilter = (next: Partial<Props['filters']>) =>
        router.get(
            videos.index().url,
            { ...filters, ...next },
            { preserveState: true, replace: true },
        );

    const tooLarge =
        form.data.video !== null && form.data.video.size > limits.max_bytes;

    return (
        <>
            <Head title="Video & Analysis" />
            <Page>
                <PageHeader
                    title="Video & Analysis"
                    description={`Upload CCTV footage (${limits.extensions.join(', ').toUpperCase()}, max ${formatBytes(limits.max_bytes)}, ${limits.max_duration_minutes} min). Videos are validated and given a preview before analysis.`}
                />
                <PageErrors keys={['video']} />

                {can('upload_video') &&
                    (sourceList.length === 0 ? (
                        <EmptyState
                            title="No active video sources yet"
                            description="Add a video source (camera angle) before uploading."
                            action={
                                <Button asChild variant="outline">
                                    <Link href={sources.index()}>
                                        Go to Video Sources
                                    </Link>
                                </Button>
                            }
                        />
                    ) : (
                        <form
                            className="space-y-4 rounded-xl border bg-card p-4 shadow-xs"
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.post(videos.store().url, {
                                    forceFormData: true,
                                });
                            }}
                        >
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                <Field
                                    label="Source / camera"
                                    htmlFor="upload-source"
                                    error={form.errors.source_id}
                                >
                                    <NativeSelect
                                        id="upload-source"
                                        value={form.data.source_id}
                                        onChange={(e) =>
                                            form.setData(
                                                'source_id',
                                                e.target.value,
                                            )
                                        }
                                    >
                                        {sourceList.map((s) => (
                                            <option key={s.id} value={s.id}>
                                                {s.name} ({s.kitchen?.name})
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>

                                <Field
                                    label="Video file"
                                    htmlFor="upload-file"
                                    error={
                                        form.errors.video ??
                                        (tooLarge
                                            ? `File exceeds limit ${formatBytes(limits.max_bytes)}.`
                                            : undefined)
                                    }
                                >
                                    <div
                                        className={cn(
                                            'border-input flex h-9 w-full min-w-0 items-center gap-2 rounded-md border bg-transparent px-1 shadow-xs',
                                            'focus-within:border-ring focus-within:ring-ring/50 focus-within:ring-[3px]',
                                        )}
                                    >
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            size="sm"
                                            className="h-7 shrink-0 px-2.5"
                                            onClick={() =>
                                                fileRef.current?.click()
                                            }
                                        >
                                            Choose file
                                        </Button>
                                        <span
                                            className={cn(
                                                'min-w-0 flex-1 truncate text-sm',
                                                form.data.video
                                                    ? 'text-foreground'
                                                    : 'text-muted-foreground',
                                            )}
                                            title={
                                                form.data.video?.name ??
                                                undefined
                                            }
                                        >
                                            {form.data.video
                                                ? form.data.video.name
                                                : 'No file chosen'}
                                        </span>
                                        <input
                                            ref={fileRef}
                                            id="upload-file"
                                            type="file"
                                            className="sr-only"
                                            accept={limits.extensions
                                                .map((e) => `.${e}`)
                                                .join(',')}
                                            onChange={(e) =>
                                                form.setData(
                                                    'video',
                                                    e.target.files?.[0] ?? null,
                                                )
                                            }
                                        />
                                    </div>
                                </Field>

                                <Field
                                    label="Recording time"
                                    htmlFor="upload-recorded"
                                    error={form.errors.recorded_at}
                                >
                                    <Input
                                        id="upload-recorded"
                                        type="datetime-local"
                                        className="h-9 [color-scheme:light] dark:[color-scheme:dark]"
                                        value={form.data.recorded_at}
                                        onChange={(e) =>
                                            form.setData(
                                                'recorded_at',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Field>
                            </div>

                            <div className="flex flex-col gap-3 border-t pt-4 sm:flex-row sm:items-center sm:justify-between">
                                <p className="text-xs text-muted-foreground">
                                    Recording time is optional · timezone{' '}
                                    <span className="font-medium text-foreground/80">
                                        {form.data.recorded_timezone}
                                    </span>
                                </p>
                                <Button
                                    className="h-9 shrink-0 sm:min-w-28"
                                    disabled={
                                        form.processing ||
                                        !form.data.video ||
                                        tooLarge
                                    }
                                    data-test="upload-video"
                                >
                                    {form.processing
                                        ? `Uploading ${form.progress?.percentage ?? 0}%`
                                        : 'Upload'}
                                </Button>
                            </div>

                            {form.progress && (
                                <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                                    <div
                                        className="h-full rounded-full bg-primary transition-all"
                                        style={{
                                            width: `${form.progress.percentage}%`,
                                        }}
                                    />
                                </div>
                            )}
                        </form>
                    ))}

                <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                    <form
                        className="flex min-w-0 flex-1 gap-2 sm:max-w-xs"
                        onSubmit={(e) => {
                            e.preventDefault();
                            applyFilter({ search: search || undefined });
                        }}
                    >
                        <Input
                            placeholder="Search file name"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="h-9 min-w-0 flex-1"
                        />
                    </form>
                    <NativeSelect
                        className="h-9 w-full sm:w-44"
                        value={filters.status ?? ''}
                        onChange={(e) =>
                            applyFilter({ status: e.target.value || undefined })
                        }
                    >
                        <option value="">All statuses</option>
                        <option value="VALIDATING">Validating</option>
                        <option value="READY">Ready</option>
                        <option value="REJECTED">Rejected</option>
                    </NativeSelect>
                    <NativeSelect
                        className="h-9 w-full sm:w-52"
                        value={filters.source_id ?? ''}
                        onChange={(e) =>
                            applyFilter({
                                source_id: e.target.value || undefined,
                            })
                        }
                    >
                        <option value="">All sources</option>
                        {sourceList.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.name}
                            </option>
                        ))}
                    </NativeSelect>
                </div>

                {page.data.length === 0 ? (
                    <EmptyState
                        title="No videos yet"
                        description="Uploaded videos appear here."
                    />
                ) : (
                    <Table>
                        <thead>
                            <tr>
                                <Th>File name</Th>
                                <Th>Source</Th>
                                <Th>Status</Th>
                                <Th>Duration</Th>
                                <Th>Size</Th>
                                <Th>Analysis</Th>
                                <Th>Uploaded</Th>
                            </tr>
                        </thead>
                        <tbody>
                            {page.data.map((v) => (
                                <tr key={v.id}>
                                    <Td className="max-w-xs truncate font-medium">
                                        <Link
                                            href={videos.show(v.id)}
                                            className="hover:underline"
                                        >
                                            {v.original_name}
                                        </Link>
                                        {v.error_message && (
                                            <p className="text-xs font-normal text-destructive">
                                                {v.error_message}
                                            </p>
                                        )}
                                    </Td>
                                    <Td>{v.source?.name}</Td>
                                    <Td>
                                        <VideoStatusBadge status={v.status} />
                                    </Td>
                                    <Td className="tabular-nums">
                                        {formatMs(v.duration_ms)}
                                    </Td>
                                    <Td className="tabular-nums">
                                        {formatBytes(v.bytes)}
                                    </Td>
                                    <Td>{v.runs_count}</Td>
                                    <Td className="whitespace-nowrap text-muted-foreground">
                                        {formatDateTime(
                                            v.created_at,
                                            tenant?.timezone,
                                        )}
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}
                <Pagination page={page} />
            </Page>
        </>
    );
}

Videos.layout = {
    breadcrumbs: [{ title: 'Video & Analysis', href: videos.index() }],
};
