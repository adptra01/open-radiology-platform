import { usePage } from '@inertiajs/react';

const SUPER_ROLES = ['SuperAdmin', 'Admin'];

/**
 * Gating UI berbasis permission Spatie.
 *
 * Sumber data: shared prop `auth.user.permissions` (diisi
 * `HandleInertiaRequests`). Admin/SuperAdmin selalu dianggap boleh — sama
 * dengan perilaku seeder yang memberi mereka seluruh permission.
 *
 * Ini hanya lapisan UX: penegakan sebenarnya tetap di API (403).
 */
export function usePermissions() {
    const { auth } = usePage().props;
    const roles = auth?.user?.roles ?? [];
    const permissions = auth?.user?.permissions ?? [];
    const isAdmin = roles.some((role) => SUPER_ROLES.includes(role));

    const can = (permission: string) =>
        isAdmin || permissions.includes(permission);

    const canAny = (...list: string[]) => list.some((p) => can(p));

    return { can, canAny, roles, permissions, isAdmin };
}
