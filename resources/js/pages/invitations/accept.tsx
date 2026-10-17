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
            <Head title="Undangan organisasi" />
            <Page>
                {!invitation || !invitation.usable ? (
                    <EmptyState
                        title="Undangan tidak berlaku"
                        description="Tautan undangan sudah dipakai, dicabut, atau kedaluwarsa. Minta Owner mengirim undangan baru."
                    />
                ) : (
                    <div className="mx-auto w-full max-w-md space-y-4 rounded-xl border p-6">
                        <h1 className="text-lg font-semibold">
                            Bergabung dengan {invitation.organization}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Anda diundang sebagai{' '}
                            <strong>{invitation.role}</strong> untuk email{' '}
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
                                Terima undangan
                            </Button>
                        ) : (
                            <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-100">
                                Undangan ini untuk email lain. Keluar lalu masuk
                                atau daftar memakai {invitation.email}.
                            </p>
                        )}
                    </div>
                )}
            </Page>
        </>
    );
}
