import type { Auth } from '@/types/auth';
import type { Membership, TenantShare } from '@/types/kitchen';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            tenant: TenantShare | null;
            memberships: Membership[];
            unreadNotifications: number;
            app: { version: string; isPlatformAdmin: boolean };
            [key: string]: unknown;
        };
    }
}
