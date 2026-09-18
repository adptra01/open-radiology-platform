import { useState } from 'react';
import { Head } from '@inertiajs/react';
import { GitMerge, Pencil, Plus, Trash2 } from 'lucide-react';
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
import { ApiError, apiDelete, apiGet, apiPost, apiPut } from '@/lib/api';
import { GENDER_LABEL, formatDate } from '@/lib/orp-format';
import type { Paginated, Patient } from '@/types/orp';

const GENDER_OPTIONS = Object.entries(GENDER_LABEL).map(([value, label]) => ({
    value,
    label,
}));

const IDENTITY_OPTIONS = [
    { value: 'KTP', label: 'KTP' },
    { value: 'SIM', label: 'SIM' },
    { value: 'Paspor', label: 'Paspor' },
    { value: 'BPJS', label: 'BPJS' },
    { value: 'Lainnya', label: 'Lainnya' },
];

export default function PatientsIndex() {
    const { can } = usePermissions();
    const list = useApiList<Patient>('/api/patients', { per_page: 15 });

    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<Patient | null>(null);
    const [saving, setSaving] = useState(false);
    const [mergeTarget, setMergeTarget] = useState<Patient | null>(null);
    const [mergeOptions, setMergeOptions] = useState<Patient[]>([]);
    const [merging, setMerging] = useState(false);

    // Opsi merge diambil lintas halaman (bukan hanya halaman aktif).
    async function openMerge(target: Patient) {
        setMergeTarget(target);

        try {
            const res = await apiGet<Paginated<Patient>>(
                '/api/patients?per_page=100',
            );
            setMergeOptions(res.data ?? []);
        } catch (error) {
            toast.error(
                error instanceof ApiError
                    ? error.message
                    : 'Gagal memuat daftar pasien',
            );
        }
    }

    if (!can('patients.view')) {
        return (
            <div className="p-4">
                <Head title="Pasien" />
                <NoAccess permission="patients.view" />
            </div>
        );
    }

    const fields: FieldDef[] = [
        { name: 'name', label: 'Nama lengkap', required: true, wide: true },
        {
            name: 'mrn',
            label: 'No. Rekam Medis',
            help: editing
                ? 'MRN tidak bisa diubah.'
                : 'Kosongkan untuk auto-generate.',
        },
        { name: 'birth_date', label: 'Tanggal lahir', type: 'date' },
        {
            name: 'gender',
            label: 'Jenis kelamin',
            type: 'select',
            options: GENDER_OPTIONS,
        },
        { name: 'phone', label: 'Telepon' },
        {
            name: 'identity_type',
            label: 'Jenis identitas',
            type: 'select',
            options: IDENTITY_OPTIONS,
        },
        { name: 'identity_number', label: 'No. identitas' },
        { name: 'address', label: 'Alamat', type: 'textarea' },
    ];

    async function submit(values: FieldValues) {
        setSaving(true);

        try {
            const payload = {
                name: values.name,
                birth_date: values.birth_date || null,
                gender: values.gender || null,
                phone: values.phone || null,
                identity_type: values.identity_type || null,
                identity_number: values.identity_number || null,
                address: values.address || null,
            };

            if (editing) {
                await apiPut(`/api/patients/${editing.id}`, payload);
                toast.success('Data pasien diperbarui');
            } else {
                await apiPost('/api/patients', {
                    ...payload,
                    mrn: values.mrn || null,
                });
                toast.success('Pasien dibuat');
            }

            setFormOpen(false);
            setEditing(null);
            list.reload();
        } catch (error) {
            toast.error(
                error instanceof ApiError
                    ? error.message
                    : 'Gagal menyimpan pasien',
            );
        } finally {
            setSaving(false);
        }
    }

    async function merge(values: FieldValues) {
        if (!mergeTarget || !values.source_id) {
            return;
        }

        setMerging(true);

        try {
            const res = await apiPost<{ orders_moved: number }>(
                `/api/patients/${mergeTarget.id}/merge`,
                { source_id: Number(values.source_id) },
            );
            toast.success(
                `Pasien digabung (${res.orders_moved} order dipindah)`,
            );
            setMergeTarget(null);
            list.reload();
        } catch (error) {
            toast.error(
                error instanceof ApiError ? error.message : 'Merge gagal',
            );
        } finally {
            setMerging(false);
        }
    }

    async function remove(patient: Patient) {
        try {
            await apiDelete(`/api/patients/${patient.id}`);
            toast.success('Pasien dihapus');
            list.reload();
        } catch (error) {
            toast.error(
                error instanceof ApiError ? error.message : 'Gagal menghapus',
            );
        }
    }

    const columns: Column<Patient>[] = [
        { key: 'mrn', header: 'MRN', cell: (p) => p.mrn ?? '—' },
        {
            key: 'name',
            header: 'Nama',
            cell: (p) => <span className="font-medium">{p.name}</span>,
        },
        {
            key: 'gender',
            header: 'L/P',
            cell: (p) =>
                p.gender ? (GENDER_LABEL[p.gender] ?? p.gender) : '—',
        },
        {
            key: 'birth_date',
            header: 'Tgl lahir',
            cell: (p) => formatDate(p.birth_date),
        },
        { key: 'phone', header: 'Telepon', cell: (p) => p.phone ?? '—' },
        {
            key: 'orders',
            header: 'Order',
            cell: (p) => (
                <Badge variant="secondary">{p.orders_count ?? 0}</Badge>
            ),
        },
        {
            key: 'actions',
            header: '',
            headClassName: 'text-right',
            className: 'text-right',
            cell: (p) => (
                <div className="flex justify-end gap-1">
                    {can('patients.edit') && (
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
                    )}
                    {can('patients.merge') && (
                        <Button
                            variant="ghost"
                            size="sm"
                            title="Gabung pasien duplikat ke pasien ini"
                            onClick={() => void openMerge(p)}
                        >
                            <GitMerge className="size-4" />
                        </Button>
                    )}
                    {can('patients.edit') && (
                        <ConfirmButton
                            title="Hapus pasien?"
                            description={`${p.name} akan di-soft delete. Pasien yang masih punya order tidak bisa dihapus.`}
                            trigger={
                                <Button variant="ghost" size="sm">
                                    <Trash2 className="size-4 text-red-500" />
                                </Button>
                            }
                            onConfirm={() => remove(p)}
                        />
                    )}
                </div>
            ),
        },
    ];

    const mergeField: FieldDef[] = [
        {
            name: 'source_id',
            label: 'Pasien sumber (duplikat, akan dihapus)',
            type: 'select',
            required: true,
            wide: true,
            options: mergeOptions
                .filter((item) => item.id !== mergeTarget?.id)
                .map((item) => ({
                    value: String(item.id),
                    label: `${item.mrn ?? '-'} · ${item.name}`,
                })),
            help: 'Semua order pasien sumber dipindah ke pasien ini.',
        },
    ];

    return (
        <div className="flex flex-1 flex-col gap-4 p-4">
            <Head title="Pasien" />

            <PageHeader
                title="Pasien"
                description="Master data pasien (MRN dibuat otomatis bila dikosongkan)."
                actions={
                    can('patients.create') && (
                        <Button
                            onClick={() => {
                                setEditing(null);
                                setFormOpen(true);
                            }}
                        >
                            <Plus className="size-4" /> Pasien Baru
                        </Button>
                    )
                }
            />

            <Card>
                <CardHeader className="flex flex-row items-center justify-between gap-3">
                    <CardTitle className="text-base">Daftar Pasien</CardTitle>
                    <Input
                        value={list.search}
                        onChange={(e) => list.setSearch(e.target.value)}
                        placeholder="Cari nama / MRN / identitas…"
                        className="w-64"
                        aria-label="Cari pasien"
                    />
                </CardHeader>
                <CardContent>
                    <DataTable
                        columns={columns}
                        rows={list.items}
                        rowKey={(p) => p.id}
                        loading={list.loading}
                        emptyMessage={
                            list.search
                                ? 'Tidak ada pasien yang cocok.'
                                : 'Belum ada pasien.'
                        }
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
                title={editing ? `Ubah ${editing.name}` : 'Pasien Baru'}
                fields={fields}
                initial={
                    editing
                        ? {
                              name: editing.name,
                              mrn: editing.mrn,
                              birth_date: editing.birth_date,
                              gender: editing.gender,
                              phone: editing.phone,
                              identity_type: editing.identity_type,
                              identity_number: editing.identity_number,
                              address: editing.address,
                          }
                        : undefined
                }
                submitting={saving}
                onSubmit={submit}
            />

            <ResourceDialog
                open={mergeTarget !== null}
                onOpenChange={(open) => !open && setMergeTarget(null)}
                title={`Gabung ke ${mergeTarget?.name ?? ''}`}
                description="Pilih pasien duplikat yang akan digabungkan."
                fields={mergeField}
                submitLabel="Gabungkan"
                submitting={merging}
                onSubmit={merge}
            />
        </div>
    );
}

PatientsIndex.layout = {
    breadcrumbs: [{ title: 'Pasien', href: '/patients' }],
};
