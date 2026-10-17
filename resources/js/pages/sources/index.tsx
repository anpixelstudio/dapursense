import { Head, Link, router, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import {
    EmptyState,
    Field,
    NativeSelect,
    Page,
    PageErrors,
    PageHeader,
    Pill,
    Table,
    Td,
    Th,
} from '@/components/kitchen/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTenant } from '@/lib/kitchen';
import locations from '@/routes/locations';
import sources from '@/routes/sources';
import videos from '@/routes/videos';
import type { Kitchen, Source } from '@/types/kitchen';

export default function Sources({
    sources: list,
    kitchens,
}: {
    sources: Source[];
    kitchens: Kitchen[];
}) {
    const { can } = useTenant();
    const canManage = can('manage_sources');
    const form = useForm({
        kitchen_id: kitchens[0]?.id ?? '',
        name: '',
        type: 'upload',
    });

    const toggle = (s: Source) =>
        router.put(
            sources.update(s.id).url,
            {
                name: s.name,
                status: s.status === 'active' ? 'inactive' : 'active',
            },
            { preserveScroll: true },
        );

    const remove = (s: Source) => {
        if (confirm(`Hapus sumber ${s.name}?`)) {
            router.delete(sources.destroy(s.id).url, { preserveScroll: true });
        }
    };

    return (
        <>
            <Head title="Sumber Video" />
            <Page>
                <PageHeader
                    title="Sumber Video"
                    description="Satu sumber mewakili satu sudut kamera. Zona digambar per sumber dan dipakai ulang untuk semua video dari sudut yang sama."
                />
                <PageErrors keys={['source']} />

                {canManage &&
                    (kitchens.length === 0 ? (
                        <EmptyState
                            title="Belum ada dapur"
                            description="Buat properti dan dapur sebelum menambahkan sumber video."
                            action={
                                <Button asChild variant="outline">
                                    <Link href={locations.index()}>
                                        Ke Lokasi
                                    </Link>
                                </Button>
                            }
                        />
                    ) : (
                        <form
                            className="grid gap-3 rounded-xl border p-4 md:grid-cols-[1fr_1fr_160px_auto] md:items-end"
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.post(sources.store().url, {
                                    preserveScroll: true,
                                    onSuccess: () => form.reset('name'),
                                });
                            }}
                        >
                            <Field
                                label="Dapur"
                                htmlFor="source-kitchen"
                                error={form.errors.kitchen_id}
                            >
                                <NativeSelect
                                    id="source-kitchen"
                                    value={form.data.kitchen_id}
                                    onChange={(e) =>
                                        form.setData(
                                            'kitchen_id',
                                            e.target.value,
                                        )
                                    }
                                >
                                    {kitchens.map((k) => (
                                        <option key={k.id} value={k.id}>
                                            {k.property?.name} · {k.name}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </Field>
                            <Field
                                label="Nama sumber / kamera"
                                htmlFor="source-name"
                                error={form.errors.name}
                            >
                                <Input
                                    id="source-name"
                                    placeholder="Kamera Dapur Utama"
                                    value={form.data.name}
                                    onChange={(e) =>
                                        form.setData('name', e.target.value)
                                    }
                                />
                            </Field>
                            <Field
                                label="Jenis"
                                htmlFor="source-type"
                                error={form.errors.type}
                            >
                                <NativeSelect
                                    id="source-type"
                                    value={form.data.type}
                                    onChange={(e) =>
                                        form.setData('type', e.target.value)
                                    }
                                >
                                    <option value="upload">Upload video</option>
                                    <option value="rtsp" disabled>
                                        RTSP (tahap P1)
                                    </option>
                                </NativeSelect>
                            </Field>
                            <Button
                                disabled={form.processing}
                                data-test="add-source"
                            >
                                Tambah
                            </Button>
                        </form>
                    ))}

                {list.length === 0 ? (
                    <EmptyState title="Belum ada sumber video" />
                ) : (
                    <Table>
                        <thead>
                            <tr>
                                <Th>Nama</Th>
                                <Th>Lokasi</Th>
                                <Th>Status</Th>
                                <Th>Video</Th>
                                <Th>Zona aktif</Th>
                                <Th />
                            </tr>
                        </thead>
                        <tbody>
                            {list.map((s) => (
                                <tr key={s.id}>
                                    <Td className="font-medium">
                                        <Link
                                            href={videos.index({
                                                query: { source_id: s.id },
                                            })}
                                            className="hover:underline"
                                        >
                                            {s.name}
                                        </Link>
                                    </Td>
                                    <Td>
                                        {s.kitchen?.property?.name} ·{' '}
                                        {s.kitchen?.name}
                                    </Td>
                                    <Td>
                                        <Pill
                                            tone={
                                                s.status === 'active'
                                                    ? 'success'
                                                    : 'neutral'
                                            }
                                        >
                                            {s.status === 'active'
                                                ? 'Aktif'
                                                : 'Nonaktif'}
                                        </Pill>
                                    </Td>
                                    <Td>{s.videos_count}</Td>
                                    <Td>{s.zones_count}</Td>
                                    <Td className="text-right whitespace-nowrap">
                                        {canManage && (
                                            <>
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() => toggle(s)}
                                                >
                                                    {s.status === 'active'
                                                        ? 'Nonaktifkan'
                                                        : 'Aktifkan'}
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    title="Hapus"
                                                    onClick={() => remove(s)}
                                                >
                                                    <Trash2 />
                                                </Button>
                                            </>
                                        )}
                                    </Td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                )}
            </Page>
        </>
    );
}

Sources.layout = {
    breadcrumbs: [{ title: 'Sumber Video', href: sources.index() }],
};
