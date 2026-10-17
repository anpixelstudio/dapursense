import { Head, router, useForm } from '@inertiajs/react';
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
} from '@/components/kitchen/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTenant } from '@/lib/kitchen';
import kitchens from '@/routes/kitchens';
import locations from '@/routes/locations';
import properties from '@/routes/properties';
import type { Property } from '@/types/kitchen';

type PendingDelete = { url: string; label: string } | null;

export default function Locations({
    properties: list,
    timezones,
}: {
    properties: Property[];
    timezones: string[];
}) {
    const { can, tenant } = useTenant();
    const canManage = can('manage_locations');
    const [pendingDelete, setPendingDelete] = useState<PendingDelete>(null);

    const propertyForm = useForm({
        name: '',
        address: '',
        timezone: tenant?.timezone ?? 'Asia/Jakarta',
    });
    const kitchenForm = useForm({
        property_id: '',
        name: '',
        description: '',
    });
    const selectedPropertyId =
        kitchenForm.data.property_id || (list[0]?.id ?? '');

    const remove = (url: string, label: string) => {
        setPendingDelete({ url, label });
    };

    return (
        <>
            <Head title="Locations" />
            <ConfirmDialog
                open={pendingDelete !== null}
                onOpenChange={(open) => !open && setPendingDelete(null)}
                title={`Delete ${pendingDelete?.label ?? 'item'}?`}
                description="This cannot be undone. Related kitchens, sources, or zones may be affected."
                confirmLabel="Delete"
                onConfirm={() => {
                    if (!pendingDelete) {
                        return;
                    }
                    router.delete(pendingDelete.url, { preserveScroll: true });
                    setPendingDelete(null);
                }}
            />
            <Page>
                <PageHeader
                    title="Locations"
                    description="Properties (outlets/hotels) and kitchens inside them. Property timezone is used for reports and schedules."
                />
                <PageErrors keys={['property', 'kitchen']} />

                {canManage && (
                    <div className="grid gap-4 lg:grid-cols-2">
                        <form
                            className="space-y-4 rounded-xl border bg-card p-4 shadow-xs"
                            onSubmit={(e) => {
                                e.preventDefault();
                                propertyForm.post(properties.store().url, {
                                    preserveScroll: true,
                                    onSuccess: () =>
                                        propertyForm.reset('name', 'address'),
                                });
                            }}
                        >
                            <p className="font-medium">Add property</p>
                            <Field
                                label="Property name"
                                htmlFor="property-name"
                                error={propertyForm.errors.name}
                            >
                                <Input
                                    id="property-name"
                                    value={propertyForm.data.name}
                                    onChange={(e) =>
                                        propertyForm.setData(
                                            'name',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                label="Address"
                                htmlFor="property-address"
                                error={propertyForm.errors.address}
                            >
                                <Input
                                    id="property-address"
                                    value={propertyForm.data.address}
                                    onChange={(e) =>
                                        propertyForm.setData(
                                            'address',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                label="Timezone"
                                htmlFor="property-tz"
                                error={propertyForm.errors.timezone}
                            >
                                <NativeSelect
                                    id="property-tz"
                                    value={propertyForm.data.timezone}
                                    onChange={(e) =>
                                        propertyForm.setData(
                                            'timezone',
                                            e.target.value,
                                        )
                                    }
                                >
                                    {timezones.map((tz) => (
                                        <option key={tz}>{tz}</option>
                                    ))}
                                </NativeSelect>
                            </Field>
                            <div className="flex justify-end border-t pt-4">
                                <Button
                                    className="h-9"
                                    disabled={propertyForm.processing}
                                    data-test="add-property"
                                >
                                    Save property
                                </Button>
                            </div>
                        </form>

                        <form
                            className="space-y-4 rounded-xl border bg-card p-4 shadow-xs"
                            onSubmit={(e) => {
                                e.preventDefault();
                                kitchenForm.transform((data) => ({
                                    ...data,
                                    property_id: selectedPropertyId,
                                }));
                                kitchenForm.post(kitchens.store().url, {
                                    preserveScroll: true,
                                    onSuccess: () =>
                                        kitchenForm.reset(
                                            'name',
                                            'description',
                                        ),
                                });
                            }}
                        >
                            <p className="font-medium">Add kitchen</p>
                            <Field
                                label="Property"
                                htmlFor="kitchen-property"
                                error={kitchenForm.errors.property_id}
                            >
                                <NativeSelect
                                    id="kitchen-property"
                                    value={selectedPropertyId}
                                    onChange={(e) =>
                                        kitchenForm.setData(
                                            'property_id',
                                            e.target.value,
                                        )
                                    }
                                >
                                    <option value="">Select property</option>
                                    {list.map((p) => (
                                        <option key={p.id} value={p.id}>
                                            {p.name}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </Field>
                            <Field
                                label="Kitchen name"
                                htmlFor="kitchen-name"
                                error={kitchenForm.errors.name}
                            >
                                <Input
                                    id="kitchen-name"
                                    value={kitchenForm.data.name}
                                    onChange={(e) =>
                                        kitchenForm.setData(
                                            'name',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                label="Description"
                                htmlFor="kitchen-desc"
                                error={kitchenForm.errors.description}
                            >
                                <Input
                                    id="kitchen-desc"
                                    value={kitchenForm.data.description}
                                    onChange={(e) =>
                                        kitchenForm.setData(
                                            'description',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <div className="flex justify-end border-t pt-4">
                                <Button
                                    className="h-9"
                                    disabled={
                                        kitchenForm.processing ||
                                        list.length === 0
                                    }
                                    data-test="add-kitchen"
                                >
                                    Save kitchen
                                </Button>
                            </div>
                        </form>
                    </div>
                )}

                {list.length === 0 ? (
                    <EmptyState
                        title="No properties yet"
                        description="Add a property first, then kitchens and video sources."
                    />
                ) : (
                    <div className="grid gap-4 md:grid-cols-2">
                        {list.map((p) => (
                            <div key={p.id} className="rounded-xl border p-4">
                                <div className="flex items-start justify-between gap-2">
                                    <div>
                                        <p className="font-medium">{p.name}</p>
                                        <p className="text-xs text-muted-foreground">
                                            {p.address || 'No address'} ·{' '}
                                            {p.timezone}
                                        </p>
                                    </div>
                                    {canManage && (
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            title="Delete property"
                                            onClick={() =>
                                                remove(
                                                    properties.destroy(p.id)
                                                        .url,
                                                    `property ${p.name}`,
                                                )
                                            }
                                        >
                                            <Trash2 />
                                        </Button>
                                    )}
                                </div>
                                <ul className="mt-3 space-y-2">
                                    {p.kitchens.length === 0 && (
                                        <li className="text-sm text-muted-foreground">
                                            No kitchens yet.
                                        </li>
                                    )}
                                    {p.kitchens.map((k) => (
                                        <li
                                            key={k.id}
                                            className="flex items-center justify-between rounded-lg bg-muted/50 px-3 py-2 text-sm"
                                        >
                                            <span>
                                                {k.name}
                                                <span className="ml-2 text-xs text-muted-foreground">
                                                    {k.sources_count ?? 0}{' '}
                                                    sources
                                                </span>
                                            </span>
                                            {canManage && (
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    title="Delete kitchen"
                                                    onClick={() =>
                                                        remove(
                                                            kitchens.destroy(
                                                                k.id,
                                                            ).url,
                                                            `kitchen ${k.name}`,
                                                        )
                                                    }
                                                >
                                                    <Trash2 />
                                                </Button>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ))}
                    </div>
                )}
            </Page>
        </>
    );
}

Locations.layout = {
    breadcrumbs: [{ title: 'Locations', href: locations.index() }],
};
