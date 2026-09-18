import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { Ban, ExternalLink, Plus } from 'lucide-react';
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
import { ApiError, apiGet, apiPost } from '@/lib/api';
import {
    ORDER_PRIORITY_COLOR,
    ORDER_PRIORITY_LABEL,
    ORDER_STATUS_COLOR,
    ORDER_STATUS_LABEL,
    formatDateTime,
    toIsoFromLocal,
} from '@/lib/orp-format';
import type {
    Doctor,
    Modality,
    Order,
    Paginated,
    Patient,
    Procedure,
} from '@/types/orp';

const STATUS_OPTIONS = Object.keys(ORDER_STATUS_LABEL);
const PRIORITY_OPTIONS = Object.keys(ORDER_PRIORITY_LABEL);

export default function OrdersIndex() {
    const { can } = usePermissions();
    const list = useApiList<Order>('/api/orders', { per_page: 15 });

    const [createOpen, setCreateOpen] = useState(false);
    const [saving, setSaving] = useState(false);
    const [patients, setPatients] = useState<Patient[]>([]);
    const [procedures, setProcedures] = useState<Procedure[]>([]);
    const [modalities, setModalities] = useState<Modality[]>([]);
    const [doctors, setDoctors] = useState<Doctor[]>([]);

    if (!can('orders.view')) {
        return (
            <div className="p-4">
                <Head title="Order" />
                <NoAccess permission="orders.view" />
            </div>
        );
    }

    async function openCreate() {
        setCreateOpen(true);

        try {
            const [p, pr, m, d] = await Promise.all([
                apiGet<Paginated<Patient>>('/api/patients?per_page=100'),
                apiGet<Paginated<Procedure>>('/api/procedures?per_page=100'),
                apiGet<Paginated<Modality>>('/api/modalities?per_page=50'),
                apiGet<Paginated<Doctor>>('/api/doctors?per_page=100'),
            ]);

            setPatients(p.data ?? []);
            setProcedures(pr.data ?? []);
            setModalities(m.data ?? []);
            setDoctors((d.data ?? []).filter((doc) => !doc.is_radiologist));
        } catch (error) {
            toast.error(
                error instanceof ApiError
                    ? error.message
                    : 'Gagal memuat referensi',
            );
        }
    }

    async function create(values: FieldValues) {
        setSaving(true);

        try {
            await apiPost('/api/orders', {
                patient_id: Number(values.patient_id),
                procedure_id: Number(values.procedure_id),
                modality_id: values.modality_id
                    ? Number(values.modality_id)
                    : null,
                referring_doctor_id: values.referring_doctor_id
                    ? Number(values.referring_doctor_id)
                    : null,
                priority: values.priority || 'ROUTINE',
                scheduled_at: toIsoFromLocal(String(values.scheduled_at ?? '')),
                status_note: values.status_note || null,
            });
            toast.success('Order dibuat');
            setCreateOpen(false);
            list.reload();
        } catch (error) {
            toast.error(
                error instanceof ApiError
                    ? error.message
                    : 'Gagal membuat order',
            );
        } finally {
            setSaving(false);
        }
    }

    async function cancel(order: Order) {
        try {
            await apiPost(`/api/orders/${order.id}/cancel`);
            toast.success('Order dibatalkan');
            list.reload();
        } catch (error) {
            toast.error(
                error instanceof ApiError
                    ? error.message
                    : 'Gagal membatalkan order',
            );
        }
    }

    const createFields: FieldDef[] = [
        {
            name: 'patient_id',
            label: 'Pasien',
            type: 'select',
            required: true,
            options: patients.map((patient) => ({
                value: String(patient.id),
                label: `${patient.mrn ?? '-'} · ${patient.name}`,
            })),
        },
        {
            name: 'procedure_id',
            label: 'Prosedur',
            type: 'select',
            required: true,
            options: procedures.map((procedure) => ({
                value: String(procedure.id),
                label: `${procedure.code} · ${procedure.name}`,
            })),
        },
        {
            name: 'modality_id',
            label: 'Modalitas',
            type: 'select',
            options: modalities.map((modality) => ({
                value: String(modality.id),
                label: `${modality.name} (${modality.ae_title})`,
            })),
        },
        {
            name: 'referring_doctor_id',
            label: 'Dokter perujuk',
            type: 'select',
            options: doctors.map((doctor) => ({
                value: String(doctor.id),
                label: `${doctor.name}${doctor.specialty ? ` — ${doctor.specialty}` : ''}`,
            })),
        },
        {
            name: 'priority',
            label: 'Prioritas',
            type: 'select',
            options: PRIORITY_OPTIONS.map((priority) => ({
                value: priority,
                label: ORDER_PRIORITY_LABEL[priority],
            })),
        },
        { name: 'scheduled_at', label: 'Jadwal (opsional)', type: 'datetime' },
        { name: 'status_note', label: 'Catatan klinis', type: 'textarea' },
    ];

    const columns: Column<Order>[] = [
        {
            key: 'order',
            header: 'Order',
            cell: (o) => (
                <div>
                    <span className="font-medium">{o.order_number}</span>
                    <div className="text-muted-foreground text-xs">
                        <code>{o.accession_number}</code>
                    </div>
                </div>
            ),
        },
        {
            key: 'patient',
            header: 'Pasien',
            cell: (o) => (
                <div>
                    <span>{o.patient?.name ?? '—'}</span>
                    <div className="text-muted-foreground text-xs">
                        {o.patient?.mrn ?? ''}
                    </div>
                </div>
            ),
        },
        {
            key: 'procedure',
            header: 'Pemeriksaan',
            cell: (o) => (
                <div>
                    <span>{o.procedure?.name ?? '—'}</span>
                    <div className="text-muted-foreground text-xs">
                        {o.modality?.name ?? o.procedure?.modality ?? ''}
                    </div>
                </div>
            ),
        },
        {
            key: 'priority',
            header: 'Prioritas',
            cell: (o) => (
                <Badge className={ORDER_PRIORITY_COLOR[o.priority] ?? ''}>
                    {ORDER_PRIORITY_LABEL[o.priority] ?? o.priority}
                </Badge>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (o) => (
                <Badge className={ORDER_STATUS_COLOR[o.status] ?? ''}>
                    {ORDER_STATUS_LABEL[o.status] ?? o.status}
                </Badge>
            ),
        },
        {
            key: 'requested',
            header: 'Diminta',
            cell: (o) => formatDateTime(o.requested_at),
        },
        {
            key: 'actions',
            header: '',
            headClassName: 'text-right',
            className: 'text-right',
            cell: (o) => (
                <div className="flex justify-end gap-1">
                    <Button
                        variant="ghost"
                        size="sm"
                        title="Detail order"
                        onClick={() => router.visit(`/orders/${o.id}`)}
                    >
                        <ExternalLink className="size-4" />
                    </Button>
                    {can('orders.cancel') &&
                        !['CANCELLED', 'COMPLETED'].includes(o.status) && (
                            <ConfirmButton
                                title="Batalkan order?"
                                description={`${o.order_number} akan berstatus CANCELLED.`}
                                confirmLabel="Batalkan"
                                trigger={
                                    <Button variant="ghost" size="sm">
                                        <Ban className="size-4 text-red-500" />
                                    </Button>
                                }
                                onConfirm={() => cancel(o)}
                            />
                        )}
                </div>
            ),
        },
    ];

    return (
        <div className="flex flex-1 flex-col gap-4 p-4">
            <Head title="Order" />

            <PageHeader
                title="Order Radiologi"
                description="Permintaan pemeriksaan: nomor order & accession dibuat otomatis."
                actions={
                    can('orders.create') && (
                        <Button onClick={() => void openCreate()}>
                            <Plus className="size-4" /> Order Baru
                        </Button>
                    )
                }
            />

            <Card>
                <CardHeader className="flex flex-wrap items-center justify-between gap-3">
                    <CardTitle className="text-base">Daftar Order</CardTitle>
                    <div className="flex flex-wrap items-center gap-2">
                        <select
                            className="bg-background rounded-md border px-3 py-2 text-sm"
                            value={String(list.params.status ?? '')}
                            onChange={(e) =>
                                list.setFilter({
                                    status: e.target.value || undefined,
                                })
                            }
                            aria-label="Filter status order"
                        >
                            <option value="">Semua status</option>
                            {STATUS_OPTIONS.map((status) => (
                                <option key={status} value={status}>
                                    {ORDER_STATUS_LABEL[status]}
                                </option>
                            ))}
                        </select>
                        <select
                            className="bg-background rounded-md border px-3 py-2 text-sm"
                            value={String(list.params.priority ?? '')}
                            onChange={(e) =>
                                list.setFilter({
                                    priority: e.target.value || undefined,
                                })
                            }
                            aria-label="Filter prioritas"
                        >
                            <option value="">Semua prioritas</option>
                            {PRIORITY_OPTIONS.map((priority) => (
                                <option key={priority} value={priority}>
                                    {ORDER_PRIORITY_LABEL[priority]}
                                </option>
                            ))}
                        </select>
                        <Input
                            type="date"
                            value={String(list.params.date ?? '')}
                            onChange={(e) =>
                                list.setFilter({
                                    date: e.target.value || undefined,
                                })
                            }
                            className="w-40"
                            aria-label="Filter tanggal"
                        />
                        <Input
                            value={list.search}
                            onChange={(e) => list.setSearch(e.target.value)}
                            placeholder="Cari order / pasien…"
                            className="w-52"
                            aria-label="Cari order"
                        />
                    </div>
                </CardHeader>
                <CardContent>
                    <DataTable
                        columns={columns}
                        rows={list.items}
                        rowKey={(o) => o.id}
                        loading={list.loading}
                        emptyMessage="Tidak ada order pada filter ini."
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
                open={createOpen}
                onOpenChange={setCreateOpen}
                title="Order Baru"
                description="Accession number dibuat otomatis (ACC-YYMMDD-XXXX)."
                fields={createFields}
                submitLabel="Buat Order"
                submitting={saving}
                onSubmit={create}
            />
        </div>
    );
}

OrdersIndex.layout = {
    breadcrumbs: [{ title: 'Order', href: '/orders' }],
};
