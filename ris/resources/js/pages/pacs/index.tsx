import { useState } from 'react';
import { Head } from '@inertiajs/react';
import { Activity, Pencil, Plus, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { DataTable, type Column } from '@/components/orp/data-table';
import { ConfirmButton } from '@/components/orp/confirm-button';
import { NoAccess, PageHeader } from '@/components/orp/page-header';
import {
    ResourceDialog,
    type FieldDef,
    type FieldValues,
} from '@/components/orp/resource-dialog';
import { useApiList } from '@/hooks/use-api-list';
import { usePermissions } from '@/hooks/use-permissions';
import { ApiError, apiDelete, apiGet, apiPost, apiPut } from '@/lib/api';
import type { PacsSource } from '@/types/orp';

export default function PacsIndex() {
    const { can } = usePermissions();
    const list = useApiList<PacsSource>('/api/pacs');
    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<PacsSource | null>(null);
    const [saving, setSaving] = useState(false);
    const [pinging, setPinging] = useState(false);

    if (!can('pacs.view')) {
        return (
            <div className="p-4">
                <Head title="PACS" />
                <NoAccess permission="pacs.view" />
            </div>
        );
    }

    const fields: FieldDef[] = [
        { name: 'name', label: 'Nama sumber PACS', required: true },
        { name: 'ae_title', label: 'AE Title', required: true },
        { name: 'host', label: 'Host', placeholder: 'orthanc' },
        {
            name: 'port',
            label: 'Port DICOM',
            type: 'number',
            placeholder: '4242',
        },
        {
            name: 'base_url',
            label: 'Base URL (DICOMweb)',
            placeholder: 'http://localhost:8042/dicom-web',
        },
        {
            name: 'qido_url',
            label: 'QIDO-RS URL',
            placeholder: 'http://…/dicom-web',
        },
        {
            name: 'wado_url',
            label: 'WADO-RS URL',
            placeholder: 'http://…/dicom-web',
        },
        {
            name: 'stow_url',
            label: 'STOW-RS URL',
            placeholder: 'http://…/dicom-web',
        },
        {
            name: 'username',
            label: 'Username (Basic auth, opsional)',
            placeholder: 'orp',
        },
        {
            name: 'password',
            label: 'Password (kosongkan bila tidak diubah)',
            type: 'password',
        },
        { name: 'is_active', label: 'Aktif', type: 'checkbox' },
    ];

    async function submit(values: FieldValues) {
        setSaving(true);

        try {
            const payload = {
                name: values.name,
                ae_title: values.ae_title,
                host: values.host || null,
                port: values.port === '' ? null : Number(values.port),
                base_url: values.base_url || null,
                qido_url: values.qido_url || null,
                wado_url: values.wado_url || null,
                stow_url: values.stow_url || null,
                username: values.username || null,
                // Password write-only: string kosong tidak dikirim agar nilai
                // lama di server tidak terhapus.
                ...(values.password ? { password: values.password } : {}),
                is_active: Boolean(values.is_active),
            };

            if (editing) {
                await apiPut(`/api/pacs/${editing.id}`, payload);
                toast.success('Sumber PACS diperbarui');
            } else {
                await apiPost('/api/pacs', payload);
                toast.success('Sumber PACS ditambahkan');
            }

            setFormOpen(false);
            setEditing(null);
            list.reload();
        } catch (error) {
            toast.error(
                error instanceof ApiError
                    ? error.message
                    : 'Gagal menyimpan PACS',
            );
        } finally {
            setSaving(false);
        }
    }

    async function remove(pacs: PacsSource) {
        try {
            await apiDelete(`/api/pacs/${pacs.id}`);
            toast.success('Sumber PACS dihapus');
            list.reload();
        } catch (error) {
            toast.error(
                error instanceof ApiError ? error.message : 'Gagal menghapus',
            );
        }
    }

    async function ping(pacs: PacsSource) {
        setPinging(true);

        try {
            const res = await apiGet<{ reachable: boolean }>(
                `/api/pacs/${pacs.id}/ping`,
            );
            toast[res.reachable ? 'success' : 'error'](
                res.reachable
                    ? `${pacs.name} merespons QIDO-RS`
                    : `${pacs.name} tidak merespons`,
            );
        } catch (error) {
            toast.error(
                error instanceof ApiError ? error.message : 'Ping gagal',
            );
        } finally {
            setPinging(false);
        }
    }

    const columns: Column<PacsSource>[] = [
        {
            key: 'name',
            header: 'Nama',
            cell: (p) => (
                <div>
                    <span className="font-medium">{p.name}</span>
                    <div className="text-muted-foreground text-xs">
                        {p.base_url ?? '—'}
                    </div>
                </div>
            ),
        },
        {
            key: 'ae',
            header: 'AE Title',
            cell: (p) => <code className="text-xs">{p.ae_title}</code>,
        },
        {
            key: 'dicoms',
            header: 'DICOM',
            cell: (p) => (p.host ? `${p.host}:${p.port ?? '—'}` : '—'),
        },
        {
            key: 'creds',
            header: 'Kredensial',
            cell: (p) =>
                p.has_credentials ? (
                    <Badge variant="outline">{p.username ?? '•••'}</Badge>
                ) : (
                    <span className="text-muted-foreground text-xs">
                        tanpa auth
                    </span>
                ),
        },
        {
            key: 'active',
            header: 'Status',
            cell: (p) =>
                p.deleted_at ? (
                    <Badge variant="secondary">Dihapus</Badge>
                ) : p.is_active ? (
                    <Badge className="bg-emerald-500">Aktif</Badge>
                ) : (
                    <Badge variant="secondary">Nonaktif</Badge>
                ),
        },
        {
            key: 'actions',
            header: '',
            headClassName: 'text-right',
            className: 'text-right',
            cell: (p) => (
                <div className="flex justify-end gap-1">
                    <Button
                        variant="ghost"
                        size="sm"
                        title="Cek koneksi QIDO-RS"
                        disabled={pinging || Boolean(p.deleted_at)}
                        onClick={() => void ping(p)}
                    >
                        <Activity className="size-4" />
                    </Button>
                    {can('pacs.edit') && !p.deleted_at && (
                        <>
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => {
                                    setEditing(p);
                                    setFormOpen(true);
                                }}
                            >
                                <Pencil className="size-4" />
                            </Button>
                            <ConfirmButton
                                title="Hapus sumber PACS?"
                                description={`${p.name} akan dinonaktifkan & di-soft delete. Transmisi yang masih berjalan menghalangi penghapusan.`}
                                trigger={
                                    <Button variant="ghost" size="sm">
                                        <Trash2 className="size-4 text-red-500" />
                                    </Button>
                                }
                                onConfirm={() => remove(p)}
                            />
                        </>
                    )}
                </div>
            ),
        },
    ];

    return (
        <div className="flex flex-1 flex-col gap-4 p-4">
            <Head title="PACS" />

            <PageHeader
                title="PACS"
                description="Sumber PACS vendor (Orthanc/DCM4CHEE) — tujuan transmisi & pencarian study."
                actions={
                    can('pacs.create') && (
                        <Button
                            onClick={() => {
                                setEditing(null);
                                setFormOpen(true);
                            }}
                        >
                            <Plus className="size-4" /> Sumber PACS
                        </Button>
                    )
                }
            />

            <Card>
                <CardHeader>
                    <CardTitle className="text-base">Daftar Sumber</CardTitle>
                </CardHeader>
                <CardContent>
                    <DataTable
                        columns={columns}
                        rows={list.items}
                        rowKey={(p) => p.id}
                        loading={list.loading}
                        emptyMessage="Belum ada sumber PACS terdaftar."
                    />
                </CardContent>
            </Card>

            <ResourceDialog
                open={formOpen}
                onOpenChange={setFormOpen}
                title={editing ? `Ubah ${editing.name}` : 'Sumber PACS Baru'}
                fields={fields}
                initial={
                    editing
                        ? {
                              name: editing.name,
                              ae_title: editing.ae_title,
                              host: editing.host,
                              port: editing.port,
                              base_url: editing.base_url,
                              qido_url: editing.qido_url,
                              wado_url: editing.wado_url,
                              stow_url: editing.stow_url,
                              username: editing.username,
                              is_active: editing.is_active,
                          }
                        : undefined
                }
                submitting={saving}
                onSubmit={submit}
            />
        </div>
    );
}

PacsIndex.layout = {
    breadcrumbs: [{ title: 'PACS', href: '/pacs' }],
};
