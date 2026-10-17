import { Head, Link, router, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/kitchen/confirm-dialog';
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
    const [pendingDelete, setPendingDelete] = useState<Source | null>(null);
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

    return (
        <>
            <Head title="Video Sources" />
            <ConfirmDialog
                open={pendingDelete !== null}
                onOpenChange={(open) => !open && setPendingDelete(null)}
                title={`Delete source ${pendingDelete?.name ?? ''}?`}
                description="Videos and zone layouts for this camera angle may become unavailable."
                confirmLabel="Delete source"
                onConfirm={() => {
                    if (!pendingDelete) {
                        return;
                    }
                    router.delete(sources.destroy(pendingDelete.id).url, {
                        preserveScroll: true,
                    });
                    setPendingDelete(null);
                }}
            />
            <Page>
                <PageHeader
                    title="Video Sources"
                    description="Each source represents one camera angle. Zones are drawn per source and reused for all videos from that angle."
                />
                <PageErrors keys={['source']} />

                {canManage &&
                    (kitchens.length === 0 ? (
                        <EmptyState
                            title="No kitchens yet"
                            description="Create a property and kitchen before adding video sources."
                            action={
                                <Button asChild variant="outline">
                                    <Link href={locations.index()}>
                                        Go to Locations
                                    </Link>
                                </Button>
                            }
                        />
                    ) : (
                        <form
                            className="space-y-4 rounded-xl border bg-card p-4 shadow-xs"
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.post(sources.store().url, {
                                    preserveScroll: true,
                                    onSuccess: () => form.reset('name'),
                                });
                            }}
                        >
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 items-start">
                                <Field
                                    label="Kitchen"
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
                                    label="Source / camera name"
                                    htmlFor="source-name"
                                    error={form.errors.name}
                                >
                                    <Input
                                        id="source-name"
                                        placeholder="Main Kitchen Camera"
                                        value={form.data.name}
                                        onChange={(e) =>
                                            form.setData('name', e.target.value)
                                        }
                                    />
                                </Field>
                                <Field
                                    label="Type"
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
                                        <option value="upload">
                                            Upload video
                                        </option>
                                        <option value="rtsp" disabled>
                                            RTSP (P1 phase)
                                        </option>
                                    </NativeSelect>
                                </Field>
                            </div>
                            <div className="flex justify-end border-t pt-4">
                                <Button
                                    className="h-9 min-w-24"
                                    disabled={form.processing}
                                    data-test="add-source"
                                >
                                    Add source
                                </Button>
                            </div>
                        </form>
                    ))}

                {list.length === 0 ? (
                    <EmptyState title="No video sources yet" />
                ) : (
                    <Table>
                        <thead>
                            <tr>
                                <Th>Name</Th>
                                <Th>Location</Th>
                                <Th>Status</Th>
                                <Th>Video</Th>
                                <Th>Active zones</Th>
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
                                                ? 'Active'
                                                : 'Inactive'}
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
                                                        ? 'Deactivate'
                                                        : 'Activate'}
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    title="Delete"
                                                    onClick={() =>
                                                        setPendingDelete(s)
                                                    }
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
    breadcrumbs: [{ title: 'Video Sources', href: sources.index() }],
};
