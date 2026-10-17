import { Head } from '@inertiajs/react';
import { EmptyState, Page } from '@/components/kitchen/ui';

export default function NoOrganization() {
    return (
        <>
            <Head title="No organization" />
            <Page>
                <EmptyState
                    title="You are not in any organization yet"
                    description="Ask an organization Owner to invite your email, then open the invitation link. Your membership may also have been deactivated."
                />
            </Page>
        </>
    );
}
