import { useState } from 'react';
import { Head } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    DataTable,
    TablePagination,
    type Column,
} from '@/components/orp/data-table';
import { ConfirmButton } from '@/components/orp/confirm-button';
import { NoAccess, PageHeader } from '@/components/orp/page-header';
import {
    ResourceDialog,
    type FieldDef,
    type FieldValues,
} from '@/components/orp/resource-dialog';
import { useApiList } from '@/hooks/use-api-list';
import { usePermissions } from '@/hooks/use-permissions';
import { ApiError, apiDelete, apiPost, apiPut } from '@/lib/api';
import type { Modality } from '@/types/orp';

const TYPE_OPTIONS = ['CR', 'DX', 'CT', 'MR', 'US', 'MG', 'RF', 'OT'].map(
    (value) => ({ value, label: value }),
);

export default function ModalitiesIndex() {
    const { can } = usePermissions();
    const list = useApiList<Modality>('/api/modalities', { per_page: 15 });

    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<Modality | null>(null);
    const [saving, setSaving] = useState(false);

    if (!can('modalities.view')) {
        return (
            <div className="p-4">
                <Head title="Modalitas" />
                <NoAccess permission="modalities.view" />
            </div>
        );
    }

    const fields: FieldDef[] = [
        { name: 'name', label: 'Nama modalitas', required: true },
        {
            name: 'ae_title',
            label: 'AE Title (DICOM)',
            required: true,
            help: 'Unik, maksimal 16 karakter (mis. CTSCAN1).',
        },
        {
            name: 'modality_type',
            label: 'Tipe',
            type: 'select',
            options: TYPE_OPTIONS,
        },
        { name: 'location', label: 'Lokasi / ruangan' },
        { name: 'host', label: 'Host', placeholder: '10.0.0.10' },
        { name: 'port', label: 'Port', type: 'number', placeholder: '104' },
        {
            name: 'is_online',
            label: 'Online (siap menerima order)',
            type: 'checkbox',
        },
    ];

    async function submit(values: FieldValues) {
        setSaving(true);

        try {
            const payload = {
                name: values.name,
                ae_title: values.ae_title,
                modality_type: values.modality_type || null,
                location: values.location || null,
                host: values.host || null,
                port: values.port === '' ? null : Number(values.port),
                is_online: Boolean(values.is_online),
            };

            if (editing) {
                await apiPut(`/api/modalities/${editing.id}`, payload);
                toast.success('Modalitas diperbarui');
            } else {
                await apiPost('/api/modalities', payload);
                toast.success('Modalitas ditambahkan');
            }

            setFormOpen(false);
            setEditing(null);
            list.reload();
        } catch (error) {
            toast.error(
                error instanceof ApiError
                    ? error.message
                    : 'Gagal menyimpan modalitas',
            );
        } finally {
            setSaving(false);
        }
    }

    async function remove(modality: Modality) {
        try {
            await apiDelete(`/api/modalities/${modality.id}`);
            toast.success('Modalitas dihapus');
            list.reload();
        } catch (error) {
            toast.error(
                error instanceof ApiError ? error.message : 'Gagal menghapus',
            );
        }
    }

    const columns: Column<Modality>[] = [
        {
            key: 'name',
            header: 'Nama',
            cell: (m) => <span className="font-medium">{m.name}</span>,
        },
        {
            key: 'ae',
            header: 'AE Title',
            cell: (m) => <code className="text-xs">{m.ae_title}</code>,
        },
        { key: 'type', header: 'Tipe', cell: (m) => m.modality_type ?? '—' },
        {
            key: 'endpoint',
            header: 'Host:Port',
            cell: (m) => (m.host ? `${m.host}:${m.port ?? '—'}` : '—'),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (m) =>
                m.is_online ? (
                    <Badge className="bg-emerald-500">Online</Badge>
                ) : (
                    <Badge variant="secondary">Offline</Badge>
                ),
        },
        {
            key: 'actions',
            header: '',
            headClassName: 'text-right',
            className: 'text-right',
            cell: (m) => (
                <div className="flex justify-end gap-1">
                    {can('modalities.edit') && (
                        <>
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => {
                                    setEditing(m);
                                    setFormOpen(true);
                                }}
                            >
                                <Pencil className="size-4" />
                            </Button>
                            <ConfirmButton
                                title="Hapus modalitas?"
                                description={`${m.name} akan dihapus. Modalitas yang masih dipakai order tidak bisa dihapus.`}
                                trigger={
                                    <Button variant="ghost" size="sm">
                                        <Trash2 className="size-4 text-red-500" />
                                    </Button>
                                }
                                onConfirm={() => remove(m)}
                            />
                        </>
                    )}
                </div>
            ),
        },
    ];

    return (
        <div className="flex flex-1 flex-col gap-4 p-4">
            <Head title="Modalitas" />

            <PageHeader
                title="Modalitas"
                description="Perangkat DICOM yang terhubung ke RIS (AE title, host, port)."
                actions={
                    can('modalities.create') && (
                        <Button
                            onClick={() => {
                                setEditing(null);
                                setFormOpen(true);
                            }}
                        >
                            <Plus className="size-4" /> Modalitas Baru
                        </Button>
                    )
                }
            />

            <Card>
                <CardHeader className="flex flex-row items-center justify-between gap-3">
                    <CardTitle className="text-base">
                        Daftar Modalitas
                    </CardTitle>
                    <Input
                        value={list.search}
                        onChange={(e) => list.setSearch(e.target.value)}
                        placeholder="Cari nama / AE title…"
                        className="w-56"
                        aria-label="Cari modalitas"
                    />
                </CardHeader>
                <CardContent>
                    <DataTable
                        columns={columns}
                        rows={list.items}
                        rowKey={(m) => m.id}
                        loading={list.loading}
                        emptyMessage="Belum ada modalitas."
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
                open={formOpen}
                onOpenChange={setFormOpen}
                title={editing ? `Ubah ${editing.name}` : 'Modalitas Baru'}
                fields={fields}
                initial={
                    editing
                        ? {
                              name: editing.name,
                              ae_title: editing.ae_title,
                              modality_type: editing.modality_type,
                              location: editing.location,
                              host: editing.host,
                              port: editing.port,
                              is_online: editing.is_online,
                          }
                        : undefined
                }
                submitting={saving}
                onSubmit={submit}
            />
        </div>
    );
}

ModalitiesIndex.layout = {
    breadcrumbs: [{ title: 'Modalitas', href: '/modalities' }],
};
