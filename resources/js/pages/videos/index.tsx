import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
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
            <Head title="Video & Analisis" />
            <Page>
                <PageHeader
                    title="Video & Analisis"
                    description={`Upload rekaman CCTV (${limits.extensions.join(', ').toUpperCase()}, maks ${formatBytes(limits.max_bytes)}, ${limits.max_duration_minutes} menit). Video divalidasi dan dibuatkan preview sebelum dapat dianalisis.`}
                />
                <PageErrors keys={['video']} />

                {can('upload_video') &&
                    (sourceList.length === 0 ? (
                        <EmptyState
                            title="Belum ada sumber video aktif"
                            description="Tambahkan sumber video (sudut kamera) sebelum upload."
                            action={
                                <Button asChild variant="outline">
                                    <Link href={sources.index()}>
                                        Ke Sumber Video
                                    </Link>
                                </Button>
                            }
                        />
                    ) : (
                        <form
                            className="grid gap-3 rounded-xl border p-4 md:grid-cols-2 lg:grid-cols-[1fr_1.4fr_1fr_auto] lg:items-end"
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.post(videos.store().url, {
                                    forceFormData: true,
                                });
                            }}
                        >
                            <Field
                                label="Sumber / kamera"
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
                                label="File video"
                                htmlFor="upload-file"
                                error={
                                    form.errors.video ??
                                    (tooLarge
                                        ? `File melebihi batas ${formatBytes(limits.max_bytes)}.`
                                        : undefined)
                                }
                            >
                                <Input
                                    id="upload-file"
                                    type="file"
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
                            </Field>
                            <Field
                                label="Waktu rekaman (opsional)"
                                htmlFor="upload-recorded"
                                error={form.errors.recorded_at}
                                hint={`Zona waktu ${form.data.recorded_timezone}`}
                            >
                                <Input
                                    id="upload-recorded"
                                    type="datetime-local"
                                    value={form.data.recorded_at}
                                    onChange={(e) =>
                                        form.setData(
                                            'recorded_at',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Button
                                disabled={
                                    form.processing ||
                                    !form.data.video ||
                                    tooLarge
                                }
                                data-test="upload-video"
                            >
                                {form.processing
                                    ? `Mengunggah ${form.progress?.percentage ?? 0}%`
                                    : 'Upload'}
                            </Button>
                            {form.progress && (
                                <div className="h-1.5 rounded bg-muted md:col-span-2 lg:col-span-4">
                                    <div
                                        className="h-full rounded bg-primary transition-all"
                                        style={{
                                            width: `${form.progress.percentage}%`,
                                        }}
                                    />
                                </div>
                            )}
                        </form>
                    ))}

                <div className="flex flex-wrap gap-2">
                    <form
                        className="flex gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            applyFilter({ search: search || undefined });
                        }}
                    >
                        <Input
                            placeholder="Cari nama file"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="w-56"
                        />
                    </form>
                    <NativeSelect
                        className="w-44"
                        value={filters.status ?? ''}
                        onChange={(e) =>
                            applyFilter({ status: e.target.value || undefined })
                        }
                    >
                        <option value="">Semua status</option>
                        <option value="VALIDATING">Divalidasi</option>
                        <option value="READY">Siap</option>
                        <option value="REJECTED">Ditolak</option>
                    </NativeSelect>
                    <NativeSelect
                        className="w-52"
                        value={filters.source_id ?? ''}
                        onChange={(e) =>
                            applyFilter({
                                source_id: e.target.value || undefined,
                            })
                        }
                    >
                        <option value="">Semua sumber</option>
                        {sourceList.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.name}
                            </option>
                        ))}
                    </NativeSelect>
                </div>

                {page.data.length === 0 ? (
                    <EmptyState
                        title="Belum ada video"
                        description="Video yang diunggah akan tampil di sini."
                    />
                ) : (
                    <Table>
                        <thead>
                            <tr>
                                <Th>Nama file</Th>
                                <Th>Sumber</Th>
                                <Th>Status</Th>
                                <Th>Durasi</Th>
                                <Th>Ukuran</Th>
                                <Th>Analisis</Th>
                                <Th>Diunggah</Th>
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
    breadcrumbs: [{ title: 'Video & Analisis', href: videos.index() }],
};
