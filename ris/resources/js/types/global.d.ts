import type { Auth } from '@/types/auth';

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
            /** URL viewer OHIF (config/ris.php → ORP_OHIF_URL). */
            ohif_url: string | null;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
