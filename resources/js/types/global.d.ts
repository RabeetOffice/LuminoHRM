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
            app: {
                timezone: string;
                /** PHP date() pattern from Settings > Company, e.g. 'd M Y'. Use formatDate(). */
                dateFormat: string;
                name: string;
                accent: string;
                logoUrl: string | null;
                logoDarkUrl: string | null;
            };
            auth: Auth;
            flash: { success: string | null; error: string | null };
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
