import { useEffect, useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    DataTable,
    TablePagination,
    type Column,
} from '@/components/orp/data-table';
import { NoAccess, PageHeader } from '@/components/orp/page-header';
import {
    ResourceDialog,
    type FieldDef,
    type FieldValues,
} from '@/components/orp/resource-dialog';
import { useApiList } from '@/hooks/use-api-list';
import { usePermissions } from '@/hooks/use-permissions';
import { ApiError, apiGet, apiPut } from '@/lib/api';
import { formatDateTime } from '@/lib/orp-format';
import type { RoleInfo } from '@/types/orp';

type ManagedUser = {
    id: number;
    name: string;
    email: string;
    roles: { id: number; name: string }[];
    created_at: string;
};

export default function UsersIndex() {
    const { isAdmin } = usePermissions();
    const currentUserId = usePage().props.auth.user.id;
    const list = useApiList<ManagedUser>('/api/users', { per_page: 15 });
    const [roles, setRoles] = useState<RoleInfo[]>([]);
    const [editing, setEditing] = useState<ManagedUser | null>(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        // Daftar role hanya bisa dibaca Admin/SuperAdmin.
        if (!isAdmin) {
            return;
        }

        apiGet<{ data: RoleInfo[] }>('/api/roles')
            .then((res) => setRoles(res.data ?? []))
            .catch((error: unknown) =>
                toast.error(
                    error instanceof ApiError
                        ? error.message
                        : 'Gagal memuat role',
                ),
            );
    }, [isAdmin]);

    if (!isAdmin) {
        return (
            <div className="p-4">
                <Head title="Pengguna" />
                <NoAccess permission="role Admin/SuperAdmin" />
            </div>
        );
    }

    async function assignRoles(values: FieldValues) {
        if (!editing) {
            return;
        }

        setSaving(true);

        try {
            const selected = roles
                .filter((role) => Boolean(values[`role_${role.name}`]))
                .map((role) => role.name);

            if (selected.length === 0) {
                toast.error('Pilih minimal satu role.');
                setSaving(false);

                return;
            }

            await apiPut(`/api/users/${editing.id}/roles`, {
                roles: selected,
            });
            toast.success(`Role ${editing.name} diperbarui`);
            setEditing(null);
            list.reload();
        } catch (error) {
            toast.error(
                error instanceof ApiError
                    ? error.message
                    : 'Gagal mengubah role',
            );
        } finally {
            setSaving(false);
        }
    }

    const roleFields: FieldDef[] = roles.map((role) => ({
        name: `role_${role.name}`,
        label: `${role.name}${role.is_system ? '' : ' (kustom)'} — ${role.permissions.length} izin`,
        type: 'checkbox',
    }));

    const columns: Column<ManagedUser>[] = [
        {
            key: 'name',
            header: 'Nama',
            cell: (u) => (
                <div>
                    <span className="font-medium">{u.name}</span>
                    <div className="text-muted-foreground text-xs">
                        {u.email}
                    </div>
                </div>
            ),
        },
        {
            key: 'roles',
            header: 'Role',
            cell: (u) => (
                <div className="flex flex-wrap gap-1">
                    {u.roles.length === 0 ? (
                        <span className="text-muted-foreground text-xs">
                            tanpa role
                        </span>
                    ) : (
                        u.roles.map((role) => (
                            <Badge key={role.name} variant="secondary">
                                {role.name}
                            </Badge>
                        ))
                    )}
                </div>
            ),
        },
        {
            key: 'created',
            header: 'Dibuat',
            cell: (u) => formatDateTime(u.created_at),
        },
        {
            key: 'actions',
            header: '',
            headClassName: 'text-right',
            className: 'text-right',
            cell: (u) =>
                u.id === currentUserId ? (
                    <span className="text-muted-foreground text-xs">
                        akun sendiri
                    </span>
                ) : (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setEditing(u)}
                    >
                        <ShieldCheck className="size-4" /> Atur Role
                    </Button>
                ),
        },
    ];

    return (
        <div className="flex flex-1 flex-col gap-4 p-4">
            <Head title="Pengguna" />

            <PageHeader
                title="Pengguna & Role"
                description="Kelola role RBAC. Admin/SuperAdmin selalu punya seluruh izin."
            />

            <Card>
                <CardHeader className="flex flex-row items-center justify-between gap-3">
                    <CardTitle className="text-base">Daftar Pengguna</CardTitle>
                    <input
                        className="bg-background w-56 rounded-md border px-3 py-2 text-sm"
                        value={list.search}
                        onChange={(e) => list.setSearch(e.target.value)}
                        placeholder="Cari nama / email…"
                        aria-label="Cari pengguna"
                    />
                </CardHeader>
                <CardContent>
                    <DataTable
                        columns={columns}
                        rows={list.items}
                        rowKey={(u) => u.id}
                        loading={list.loading}
                        emptyMessage="Belum ada pengguna."
                        footer={
                            list.meta && (
                                <TablePagination
                                    page={list.meta.current_page}
                                    lastPage={list.meta.last_page}
                                    total={list.meta.total}
                                    from={list.meta.from}
                                    to={list.meta.to}
                                    onPage={list.setPage}
                                />
                            )
                        }
                    />
                </CardContent>
            </Card>

            <ResourceDialog
                open={editing !== null}
                onOpenChange={(open) => !open && setEditing(null)}
                title={`Role untuk ${editing?.name ?? ''}`}
                description="Centang role yang berlaku. Role akun sendiri tidak bisa diubah."
                fields={roleFields}
                initial={Object.fromEntries(
                    roles.map((role) => [
                        `role_${role.name}`,
                        (editing?.roles ?? []).some(
                            (r) => r.name === role.name,
                        ),
                    ]),
                )}
                submitLabel="Simpan Role"
                submitting={saving}
                onSubmit={assignRoles}
            />
        </div>
    );
}

UsersIndex.layout = {
    breadcrumbs: [{ title: 'Pengguna', href: '/users' }],
};
