import { Head, router, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
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

export default function Locations({
    properties: list,
    timezones,
}: {
    properties: Property[];
    timezones: string[];
}) {
    const { can, tenant } = useTenant();
    const canManage = can('manage_locations');

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
        if (confirm(`Hapus ${label}?`)) {
            router.delete(url, { preserveScroll: true });
        }
    };

    return (
        <>
            <Head title="Lokasi" />
            <Page>
                <PageHeader
                    title="Lokasi"
                    description="Properti (outlet/hotel) dan dapur di dalamnya. Zona waktu properti dipakai untuk laporan dan jadwal."
                />
                <PageErrors keys={['property', 'kitchen']} />

                {canManage && (
                    <div className="grid gap-4 lg:grid-cols-2">
                        <form
                            className="space-y-3 rounded-xl border p-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                propertyForm.post(properties.store().url, {
                                    preserveScroll: true,
                                    onSuccess: () =>
                                        propertyForm.reset('name', 'address'),
                                });
                            }}
                        >
                            <p className="font-medium">Tambah properti</p>
                            <Field
                                label="Nama properti"
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
                                label="Alamat"
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
                                label="Zona waktu"
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
                            <Button
                                disabled={propertyForm.processing}
                                data-test="add-property"
                            >
                                Simpan properti
                            </Button>
                        </form>

                        <form
                            className="space-y-3 rounded-xl border p-4"
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
                            <p className="font-medium">Tambah dapur</p>
                            <Field
                                label="Properti"
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
                                    <option value="">Pilih properti</option>
                                    {list.map((p) => (
                                        <option key={p.id} value={p.id}>
                                            {p.name}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </Field>
                            <Field
                                label="Nama dapur"
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
                                label="Keterangan"
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
                            <Button
                                disabled={
                                    kitchenForm.processing || list.length === 0
                                }
                                data-test="add-kitchen"
                            >
                                Simpan dapur
                            </Button>
                        </form>
                    </div>
                )}

                {list.length === 0 ? (
                    <EmptyState
                        title="Belum ada properti"
                        description="Tambahkan properti terlebih dahulu, lalu dapur dan sumber video."
                    />
                ) : (
                    <div className="grid gap-4 md:grid-cols-2">
                        {list.map((p) => (
                            <div key={p.id} className="rounded-xl border p-4">
                                <div className="flex items-start justify-between gap-2">
                                    <div>
                                        <p className="font-medium">{p.name}</p>
                                        <p className="text-xs text-muted-foreground">
                                            {p.address || 'Tanpa alamat'} ·{' '}
                                            {p.timezone}
                                        </p>
                                    </div>
                                    {canManage && (
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            title="Hapus properti"
                                            onClick={() =>
                                                remove(
                                                    properties.destroy(p.id)
                                                        .url,
                                                    `properti ${p.name}`,
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
                                            Belum ada dapur.
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
                                                    sumber
                                                </span>
                                            </span>
                                            {canManage && (
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    title="Hapus dapur"
                                                    onClick={() =>
                                                        remove(
                                                            kitchens.destroy(
                                                                k.id,
                                                            ).url,
                                                            `dapur ${k.name}`,
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
    breadcrumbs: [{ title: 'Lokasi', href: locations.index() }],
};
