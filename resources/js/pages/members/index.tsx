import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import {
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
import { formatDateTime, ROLE_LABELS, useTenant } from '@/lib/kitchen';
import members from '@/routes/members';

type Props = {
    members: {
        id: string;
        name: string;
        email: string;
        role: string;
        status: string;
        joined_at: string;
    }[];
    invitations: {
        id: string;
        email: string;
        role: string;
        expires_at: string;
        created_at: string;
    }[];
    roles: { value: string; label: string }[];
};

export default function Members({ members: list, invitations, roles }: Props) {
    const { can, tenant } = useTenant();
    const { auth } = usePage().props;
    const canManage = can('manage_members');
    const form = useForm({ email: '', role: 'operator' });

    const update = (id: string, data: Record<string, string>) =>
        router.patch(members.update(id).url, data, { preserveScroll: true });

    return (
        <>
            <Head title="Anggota" />
            <Page>
                <PageHeader
                    title="Anggota"
                    description="Akses dibatasi per peran. Owner mengelola anggota dan sistem; Supervisor mengatur sumber, aturan, analisis, dan meninjau anomali; Operator upload, playback, dan melihat event; Viewer hanya melihat dan mengunduh laporan."
                />
                <PageErrors keys={['role']} />

                {canManage && (
                    <form
                        className="flex flex-wrap items-end gap-3 rounded-xl border p-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(members.invite().url, {
                                preserveScroll: true,
                                onSuccess: () => form.reset('email'),
                            });
                        }}
                    >
                        <Field
                            label="Email"
                            htmlFor="invite-email"
                            error={form.errors.email}
                        >
                            <Input
                                id="invite-email"
                                type="email"
                                className="w-72"
                                value={form.data.email}
                                onChange={(e) =>
                                    form.setData('email', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Peran"
                            htmlFor="invite-role"
                            error={form.errors.role}
                        >
                            <NativeSelect
                                id="invite-role"
                                className="w-40"
                                value={form.data.role}
                                onChange={(e) =>
                                    form.setData('role', e.target.value)
                                }
                            >
                                {roles
                                    .filter((r) => r.value !== 'owner')
                                    .map((r) => (
                                        <option key={r.value} value={r.value}>
                                            {r.label}
                                        </option>
                                    ))}
                            </NativeSelect>
                        </Field>
                        <Button
                            disabled={form.processing}
                            data-test="invite-member"
                        >
                            Kirim undangan
                        </Button>
                    </form>
                )}

                <Table>
                    <thead>
                        <tr>
                            <Th>Nama</Th>
                            <Th>Email</Th>
                            <Th>Peran</Th>
                            <Th>Status</Th>
                            <Th>Bergabung</Th>
                        </tr>
                    </thead>
                    <tbody>
                        {list.map((m) => (
                            <tr key={m.id}>
                                <Td className="font-medium">
                                    {m.name}
                                    {m.email === auth.user.email && (
                                        <span className="ml-1 text-xs text-muted-foreground">
                                            (Anda)
                                        </span>
                                    )}
                                </Td>
                                <Td>{m.email}</Td>
                                <Td>
                                    {canManage ? (
                                        <NativeSelect
                                            className="h-8 w-36"
                                            value={m.role}
                                            onChange={(e) =>
                                                update(m.id, {
                                                    role: e.target.value,
                                                })
                                            }
                                        >
                                            {roles.map((r) => (
                                                <option
                                                    key={r.value}
                                                    value={r.value}
                                                >
                                                    {r.label}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                    ) : (
                                        (ROLE_LABELS[m.role] ?? m.role)
                                    )}
                                </Td>
                                <Td>
                                    <div className="flex items-center gap-2">
                                        <Pill
                                            tone={
                                                m.status === 'active'
                                                    ? 'success'
                                                    : 'neutral'
                                            }
                                        >
                                            {m.status === 'active'
                                                ? 'Aktif'
                                                : 'Ditangguhkan'}
                                        </Pill>
                                        {canManage && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    update(m.id, {
                                                        status:
                                                            m.status ===
                                                            'active'
                                                                ? 'suspended'
                                                                : 'active',
                                                    })
                                                }
                                            >
                                                {m.status === 'active'
                                                    ? 'Tangguhkan'
                                                    : 'Aktifkan'}
                                            </Button>
                                        )}
                                    </div>
                                </Td>
                                <Td className="text-muted-foreground">
                                    {formatDateTime(
                                        m.joined_at,
                                        tenant?.timezone,
                                    )}
                                </Td>
                            </tr>
                        ))}
                    </tbody>
                </Table>

                {invitations.length > 0 && (
                    <section className="space-y-2">
                        <h2 className="font-medium">Undangan tertunda</h2>
                        <ul className="divide-y rounded-xl border">
                            {invitations.map((i) => (
                                <li
                                    key={i.id}
                                    className="flex items-center gap-3 px-4 py-2 text-sm"
                                >
                                    <span className="font-medium">
                                        {i.email}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {ROLE_LABELS[i.role] ?? i.role} ·
                                        berlaku sampai{' '}
                                        {formatDateTime(
                                            i.expires_at,
                                            tenant?.timezone,
                                        )}
                                    </span>
                                    {canManage && (
                                        <Button
                                            className="ml-auto"
                                            variant="ghost"
                                            size="icon"
                                            title="Batalkan undangan"
                                            onClick={() =>
                                                router.delete(
                                                    members.invitations.destroy(
                                                        i.id,
                                                    ).url,
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            <Trash2 />
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </Page>
        </>
    );
}

Members.layout = {
    breadcrumbs: [{ title: 'Anggota', href: members.index() }],
};
