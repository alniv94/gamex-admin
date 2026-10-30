import { usePage } from '@inertiajs/vue3';
import type { AdminPageProps } from '../types';

export function usePermissions() {
    const page = usePage<AdminPageProps>();
    const can = (permission: string): boolean =>
        page.props.auth.user?.permissions.includes(permission) ?? false;
    const canAny = (permissions: string[]): boolean => permissions.some(can);

    return { can, canAny };
}
