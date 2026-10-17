import { Head, Link, router } from '@inertiajs/react';
import {
    EmptyState,
    Page,
    PageHeader,
    SimulationBadge,
} from '@/components/kitchen/ui';
import { Button } from '@/components/ui/button';
import { formatDateTime, useTenant } from '@/lib/kitchen';
import notifications from '@/routes/notifications';

type Notification = {
    id: string;
    read_at: string | null;
    created_at: string;
    data: {
        kind: string;
        title: string;
        body: string;
        url?: string;
        is_simulation?: boolean;
    };
};

export default function Notifications({
    notifications: list,
}: {
    notifications: Notification[];
}) {
    const { tenant } = useTenant();
    const unread = list.some((n) => !n.read_at);

    return (
        <>
            <Head title="Notifications" />
            <Page>
                <PageHeader
                    title="Notifications"
                    actions={
                        unread && (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        notifications.read().url,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Tandai semua dibaca
                            </Button>
                        )
                    }
                />
                {list.length === 0 ? (
                    <EmptyState
                        title="No notifications yet"
                        description="Notifications appear when analysis completes with events needing review or reports ready to download."
                    />
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {list.map((n) => (
                            <li
                                key={n.id}
                                className={`flex items-start gap-3 px-4 py-3 text-sm ${n.read_at ? '' : 'bg-primary/5'}`}
                            >
                                {!n.read_at && (
                                    <span className="mt-1.5 size-2 rounded-full bg-primary" />
                                )}
                                <div className="flex-1">
                                    <p className="flex items-center gap-2 font-medium">
                                        {n.data.url ? (
                                            <Link
                                                href={n.data.url}
                                                className="hover:underline"
                                            >
                                                {n.data.title}
                                            </Link>
                                        ) : (
                                            n.data.title
                                        )}
                                        {n.data.is_simulation && (
                                            <SimulationBadge />
                                        )}
                                    </p>
                                    <p className="text-muted-foreground">
                                        {n.data.body}
                                    </p>
                                </div>
                                <span className="text-xs whitespace-nowrap text-muted-foreground">
                                    {formatDateTime(
                                        n.created_at,
                                        tenant?.timezone,
                                    )}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </Page>
        </>
    );
}

Notifications.layout = {
    breadcrumbs: [{ title: 'Notifications', href: notifications.index() }],
};
