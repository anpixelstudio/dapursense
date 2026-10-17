import { Head } from '@inertiajs/react';
import { EmptyState, Page } from '@/components/kitchen/ui';

export default function NoOrganization() {
    return (
        <>
            <Head title="Belum ada organisasi" />
            <Page>
                <EmptyState
                    title="Anda belum tergabung di organisasi mana pun"
                    description="Minta Owner organisasi mengirim undangan ke email Anda, lalu buka tautan undangan tersebut. Keanggotaan Anda mungkin juga telah dinonaktifkan."
                />
            </Page>
        </>
    );
}
