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
            <Head title="Members" />
            <Page>
                <PageHeader
                    title="Members"
                    description="Access is role-based. Owners manage members and system; Supervisors manage sources, rules, analysis, and review anomalies; Operators upload, playback, and view events; Viewers read-only and download reports."
                />
                <PageErrors keys={['role']} />

                {canManage && (
                    <form
                        className="space-y-4 rounded-xl border bg-card p-4 shadow-xs"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(members.invite().url, {
                                preserveScroll: true,
                                onSuccess: () => form.reset('email'),
                            });
                        }}
                    >
                        <div className="grid gap-4 sm:grid-cols-2 items-start">
                            <Field
                                label="Email"
                                htmlFor="invite-email"
                                error={form.errors.email}
                            >
                                <Input
                                    id="invite-email"
                                    type="email"
                                    value={form.data.email}
                                    onChange={(e) =>
                                        form.setData('email', e.target.value)
                                    }
                                />
                            </Field>
                            <Field
                                label="Role"
                                htmlFor="invite-role"
                                error={form.errors.role}
                            >
                                <NativeSelect
                                    id="invite-role"
                                    value={form.data.role}
                                    onChange={(e) =>
                                        form.setData('role', e.target.value)
                                    }
                                >
                                    {roles
                                        .filter((r) => r.value !== 'owner')
                                        .map((r) => (
                                            <option
                                                key={r.value}
                                                value={r.value}
                                            >
                                                {r.label}
                                            </option>
                                        ))}
                                </NativeSelect>
                            </Field>
                        </div>
                        <div className="flex justify-end border-t pt-4">
                            <Button
                                className="h-9"
                                disabled={form.processing}
                                data-test="invite-member"
                            >
                                Send invitation
                            </Button>
                        </div>
                    </form>
                )}

                <Table>
                    <thead>
                        <tr>
                            <Th>Name</Th>
                            <Th>Email</Th>
                            <Th>Role</Th>
                            <Th>Status</Th>
                            <Th>Joined</Th>
                        </tr>
                    </thead>
                    <tbody>
                        {list.map((m) => (
                            <tr key={m.id}>
                                <Td className="font-medium">
                                    {m.name}
                                    {m.email === auth.user.email && (
                                        <span className="ml-1 text-xs text-muted-foreground">
                                            (you)
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
                                                ? 'Active'
                                                : 'Suspended'}
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
                                                    ? 'Suspend'
                                                    : 'Activate'}
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
                        <h2 className="font-medium">Pending invitations</h2>
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
                                        valid until{' '}
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
                                            title="Cancel invitation"
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
    breadcrumbs: [{ title: 'Members', href: members.index() }],
};
