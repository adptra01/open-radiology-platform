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
import type { Doctor } from '@/types/orp';

export default function DoctorsIndex() {
    const { can } = usePermissions();
    const list = useApiList<Doctor>('/api/doctors', { per_page: 15 });

    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<Doctor | null>(null);
    const [saving, setSaving] = useState(false);
    const [radiologistsOnly, setRadiologistsOnly] = useState(false);

    if (!can('referring-doctors.view')) {
        return (
            <div className="p-4">
                <Head title="Dokter" />
                <NoAccess permission="referring-doctors.view" />
            </div>
        );
    }

    const fields: FieldDef[] = [
        { name: 'name', label: 'Nama dokter', required: true, wide: true },
        { name: 'specialty', label: 'Spesialisasi' },
        { name: 'license_number', label: 'No. SIP / izin' },
        { name: 'phone', label: 'Telepon' },
        { name: 'email', label: 'Email', type: 'email' },
        {
            name: 'is_radiologist',
            label: 'Dokter radiologi (bisa menandatangani report)',
            type: 'checkbox',
        },
    ];

    async function submit(values: FieldValues) {
        setSaving(true);

        try {
            const payload = {
                name: values.name,
                specialty: values.specialty || null,
                license_number: values.license_number || null,
                phone: values.phone || null,
                email: values.email || null,
                is_radiologist: Boolean(values.is_radiologist),
            };

            if (editing) {
                await apiPut(`/api/doctors/${editing.id}`, payload);
                toast.success('Data dokter diperbarui');
            } else {
                await apiPost('/api/doctors', payload);
                toast.success('Dokter dibuat');
            }

            setFormOpen(false);
            setEditing(null);
            list.reload();
        } catch (error) {
            toast.error(
                error instanceof ApiError
                    ? error.message
                    : 'Gagal menyimpan dokter',
            );
        } finally {
            setSaving(false);
        }
    }

    async function remove(doctor: Doctor) {
        try {
            await apiDelete(`/api/doctors/${doctor.id}`);
            toast.success('Dokter dihapus');
            list.reload();
        } catch (error) {
            toast.error(
                error instanceof ApiError ? error.message : 'Gagal menghapus',
            );
        }
    }

    const columns: Column<Doctor>[] = [
        {
            key: 'name',
            header: 'Nama',
            cell: (d) => <span className="font-medium">{d.name}</span>,
        },
        {
            key: 'specialty',
            header: 'Spesialisasi',
            cell: (d) => d.specialty ?? '—',
        },
        {
            key: 'license',
            header: 'No. SIP',
            cell: (d) => d.license_number ?? '—',
        },
        { key: 'phone', header: 'Telepon', cell: (d) => d.phone ?? '—' },
        {
            key: 'radiologist',
            header: 'Radiolog',
            cell: (d) =>
                d.is_radiologist ? (
                    <Badge className="bg-emerald-500">Ya</Badge>
                ) : (
                    <span className="text-muted-foreground">Tidak</span>
                ),
        },
        {
            key: 'actions',
            header: '',
            headClassName: 'text-right',
            className: 'text-right',
            cell: (d) => (
                <div className="flex justify-end gap-1">
                    {can('referring-doctors.edit') && (
                        <>
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => {
                                    setEditing(d);
                                    setFormOpen(true);
                                }}
                            >
                                <Pencil className="size-4" />
                            </Button>
                            <ConfirmButton
                                title="Hapus dokter?"
                                description={`${d.name} akan di-soft delete.`}
                                trigger={
                                    <Button variant="ghost" size="sm">
                                        <Trash2 className="size-4 text-red-500" />
                                    </Button>
                                }
                                onConfirm={() => remove(d)}
                            />
                        </>
                    )}
                </div>
            ),
        },
    ];

    return (
        <div className="flex flex-1 flex-col gap-4 p-4">
            <Head title="Dokter" />

            <PageHeader
                title="Dokter"
                description="Dokter perujuk dan dokter radiologi."
                actions={
                    can('referring-doctors.create') && (
                        <Button
                            onClick={() => {
                                setEditing(null);
                                setFormOpen(true);
                            }}
                        >
                            <Plus className="size-4" /> Dokter Baru
                        </Button>
                    )
                }
            />

            <Card>
                <CardHeader className="flex flex-row items-center justify-between gap-3">
                    <CardTitle className="text-base">Daftar Dokter</CardTitle>
                    <div className="flex items-center gap-2">
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                className="size-4"
                                checked={radiologistsOnly}
                                onChange={(e) => {
                                    setRadiologistsOnly(e.target.checked);
                                    list.setFilter({
                                        is_radiologist: e.target.checked
                                            ? 1
                                            : undefined,
                                    });
                                }}
                            />
                            Hanya radiolog
                        </label>
                        <Input
                            value={list.search}
                            onChange={(e) => list.setSearch(e.target.value)}
                            placeholder="Cari nama / spesialisasi…"
                            className="w-56"
                            aria-label="Cari dokter"
                        />
                    </div>
                </CardHeader>
                <CardContent>
                    <DataTable
                        columns={columns}
                        rows={list.items}
                        rowKey={(d) => d.id}
                        loading={list.loading}
                        emptyMessage="Belum ada dokter."
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
                title={editing ? `Ubah ${editing.name}` : 'Dokter Baru'}
                fields={fields}
                initial={
                    editing
                        ? {
                              name: editing.name,
                              specialty: editing.specialty,
                              license_number: editing.license_number,
                              phone: editing.phone,
                              email: editing.email,
                              is_radiologist: editing.is_radiologist,
                          }
                        : undefined
                }
                submitting={saving}
                onSubmit={submit}
            />
        </div>
    );
}

DoctorsIndex.layout = {
    breadcrumbs: [{ title: 'Dokter', href: '/doctors' }],
};
