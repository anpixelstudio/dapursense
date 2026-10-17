import { Head, useForm } from '@inertiajs/react';
import { EmptyState, Page } from '@/components/kitchen/ui';
import { Button } from '@/components/ui/button';
import invitations from '@/routes/invitations';

type Props = {
    token: string;
    invitation: {
        organization: string;
        email: string;
        role: string;
        usable: boolean;
        email_matches: boolean;
    } | null;
};

export default function AcceptInvitation({ token, invitation }: Props) {
    const form = useForm({});

    return (
        <>
            <Head title="Organization invitation" />
            <Page>
                {!invitation || !invitation.usable ? (
                    <EmptyState
                        title="Invitation invalid"
                        description="This invitation link was used, revoked, or expired. Ask an Owner to send a new invitation."
                    />
                ) : (
                    <div className="mx-auto w-full max-w-md space-y-4 rounded-xl border p-6">
                        <h1 className="text-lg font-semibold">
                            Join {invitation.organization}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            You were invited as{' '}
                            <strong>{invitation.role}</strong> for email{' '}
                            {invitation.email}.
                        </p>
                        {invitation.email_matches ? (
                            <Button
                                className="w-full"
                                disabled={form.processing}
                                onClick={() =>
                                    form.post(invitations.accept(token).url)
                                }
                                data-test="accept-invitation"
                            >
                                Accept invitation
                            </Button>
                        ) : (
                            <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-100">
                                This invitation is for a different email. Log out and sign in
                                or register with {invitation.email}.
                            </p>
                        )}
                    </div>
                )}
            </Page>
        </>
    );
}
