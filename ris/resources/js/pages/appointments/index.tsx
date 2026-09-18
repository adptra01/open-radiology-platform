import { useState } from 'react';
import { Head } from '@inertiajs/react';
import { CalendarClock, Plus, RefreshCcw } from 'lucide-react';
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
import { NoAccess, PageHeader } from '@/components/orp/page-header';
import {
    ResourceDialog,
    type FieldDef,
    type FieldValues,
} from '@/components/orp/resource-dialog';
import { useApiList } from '@/hooks/use-api-list';
import { usePermissions } from '@/hooks/use-permissions';
import { ApiError, apiGet, apiPost, apiPut } from '@/lib/api';
import {
    APPOINTMENT_STATUS_COLOR,
    APPOINTMENT_STATUS_LABEL,
    APPOINTMENT_TRANSITIONS,
    formatDateTime,
    toIsoFromLocal,
} from '@/lib/orp-format';
import type { Appointment, Order, Paginated } from '@/types/orp';

const STATUS_OPTIONS = Object.keys(APPOINTMENT_STATUS_LABEL);

/** Konversi ISO → nilai input datetime-local (waktu lokal). */
function toLocalInput(value: string): string {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const pad = (n: number) => String(n).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(
        date.getDate(),
    )}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

export default function AppointmentsIndex() {
    const { can } = usePermissions();
    const list = useApiList<Appointment>('/api/appointments', {
        per_page: 15,
    });

    const [orders, setOrders] = useState<Order[]>([]);
    const [createOpen, setCreateOpen] = useState(false);
    const [saving, setSaving] = useState(false);
    const [rescheduling, setRescheduling] = useState<Appointment | null>(null);
    const [pendingId, setPendingId] = useState<number | null>(null);

    if (!can('scheduling.view')) {
        return (
            <div className="p-4">
                <Head title="Jadwal" />
                <NoAccess permission="scheduling.view" />
            </div>
        );
    }

    async function loadOrders() {
        try {
            const res = await apiGet<Paginated<Order>>(
                '/api/orders?per_page=100',
            );
            setOrders(
                (res.data ?? []).filter(
                    (order) => order.status !== 'CANCELLED',
                ),
            );
        } catch (error) {
            toast.error(
                error instanceof ApiError
                    ? error.message
                    : 'Gagal memuat order',
            );
        }
    }

    async function create(values: FieldValues) {
        setSaving(true);

        try {
            await apiPost('/api/appointments', {
                order_id: Number(values.order_id),
                modality_id: values.modality_id
                    ? Number(values.modality_id)
                    : null,
                scheduled_at: toIsoFromLocal(String(values.scheduled_at)),
                notes: values.notes || null,
            });
            toast.success('Jadwal dibuat');
            setCreateOpen(false);
            list.reload();
        } catch (error) {
            toast.error(
                error instanceof ApiError ? error.message : 'Gagal menyimpan',
            );
        } finally {
            setSaving(false);
        }
    }

    async function transition(appointment: Appointment, target: string) {
        setPendingId(appointment.id);

        try {
            await apiPost(`/api/appointments/${appointment.id}/transition`, {
                target,
            });
            toast.success(`Status → ${target}`);
            list.reload();
        } catch (error) {
            toast.error(
                error instanceof ApiError ? error.message : 'Transisi gagal',
            );
        } finally {
            setPendingId(null);
        }
    }

    async function reschedule(values: FieldValues) {
        if (!rescheduling) {
            return;
        }

        setSaving(true);

        try {
            await apiPut(`/api/appointments/${rescheduling.id}`, {
                scheduled_at: toIsoFromLocal(String(values.scheduled_at)),
                notes: values.notes || null,
            });
            toast.success('Jadwal diperbarui');
            setRescheduling(null);
            list.reload();
        } catch (error) {
            toast.error(
                error instanceof ApiError
                    ? error.message
                    : 'Gagal menjadwalkan ulang',
            );
        } finally {
            setSaving(false);
        }
    }

    const createFields: FieldDef[] = [
        {
            name: 'order_id',
            label: 'Order',
            type: 'select',
            required: true,
            wide: true,
            options: orders.map((order) => ({
                value: String(order.id),
                label: `${order.order_number} · ${order.patient?.name ?? '—'} · ${order.procedure?.name ?? ''}`,
            })),
            help: 'Hanya order yang belum dibatalkan yang ditampilkan (100 terbaru).',
        },
        {
            name: 'scheduled_at',
            label: 'Waktu jadwal',
            type: 'datetime',
            required: true,
        },
        { name: 'notes', label: 'Catatan', type: 'textarea' },
    ];

    const columns: Column<Appointment>[] = [
        {
            key: 'when',
            header: 'Jadwal',
            cell: (a) => (
                <span className="font-medium whitespace-nowrap">
                    {formatDateTime(a.scheduled_at)}
                </span>
            ),
        },
        {
            key: 'patient',
            header: 'Pasien',
            cell: (a) => (
                <div>
                    <span>{a.order?.patient?.name ?? '—'}</span>
                    <div className="text-muted-foreground text-xs">
                        {a.order?.accession_number ?? ''}
                    </div>
                </div>
            ),
        },
        {
            key: 'procedure',
            header: 'Pemeriksaan',
            cell: (a) => a.order?.procedure?.name ?? '—',
        },
        {
            key: 'modality',
            header: 'Modalitas',
            cell: (a) => a.modality?.name ?? a.order?.modality?.name ?? '—',
        },
        {
            key: 'status',
            header: 'Status',
            cell: (a) => (
                <Badge className={APPOINTMENT_STATUS_COLOR[a.status] ?? ''}>
                    {APPOINTMENT_STATUS_LABEL[a.status] ?? a.status}
                </Badge>
            ),
        },
        {
            key: 'actions',
            header: '',
            headClassName: 'text-right',
            className: 'text-right',
            cell: (a) => (
                <div className="flex flex-wrap justify-end gap-1">
                    {(APPOINTMENT_TRANSITIONS[a.status] ?? []).map((next) => (
                        <Button
                            key={next.target}
                            variant="outline"
                            size="sm"
                            disabled={pendingId === a.id}
                            onClick={() => void transition(a, next.target)}
                        >
                            {next.label}
                        </Button>
                    ))}
                    {can('scheduling.reschedule') &&
                        ['SCHEDULED', 'CONFIRMED'].includes(a.status) && (
                            <Button
                                variant="ghost"
                                size="sm"
                                title="Jadwalkan ulang"
                                onClick={() => setRescheduling(a)}
                            >
                                <RefreshCcw className="size-4" />
                            </Button>
                        )}
                </div>
            ),
        },
    ];

    return (
        <div className="flex flex-1 flex-col gap-4 p-4">
            <Head title="Jadwal" />

            <PageHeader
                title="Penjadwalan"
                description="Slot pemeriksaan modalitas: konfirmasi, check-in, selesai."
                actions={
                    can('scheduling.create') && (
                        <Button
                            onClick={() => {
                                void loadOrders();
                                setCreateOpen(true);
                            }}
                        >
                            <Plus className="size-4" /> Jadwal Baru
                        </Button>
                    )
                }
            />

            <Card>
                <CardHeader className="flex flex-wrap items-center justify-between gap-3">
                    <CardTitle className="flex items-center gap-2 text-base">
                        <CalendarClock className="size-4" /> Daftar Jadwal
                    </CardTitle>
                    <div className="flex flex-wrap items-center gap-2">
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
                        <select
                            className="bg-background rounded-md border px-3 py-2 text-sm"
                            value={String(list.params.status ?? '')}
                            onChange={(e) =>
                                list.setFilter({
                                    status: e.target.value || undefined,
                                })
                            }
                            aria-label="Filter status"
                        >
                            <option value="">Semua status</option>
                            {STATUS_OPTIONS.map((status) => (
                                <option key={status} value={status}>
                                    {APPOINTMENT_STATUS_LABEL[status]}
                                </option>
                            ))}
                        </select>
                        <Input
                            value={list.search}
                            onChange={(e) => list.setSearch(e.target.value)}
                            placeholder="Cari pasien…"
                            className="w-48"
                            aria-label="Cari jadwal"
                        />
                    </div>
                </CardHeader>
                <CardContent>
                    <DataTable
                        columns={columns}
                        rows={list.items}
                        rowKey={(a) => a.id}
                        loading={list.loading}
                        emptyMessage="Tidak ada jadwal pada filter ini."
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
                title="Jadwal Baru"
                description="Pilih order lalu tentukan waktu pemeriksaan."
                fields={createFields}
                submitting={saving}
                onSubmit={create}
            />

            <ResourceDialog
                open={rescheduling !== null}
                onOpenChange={(open) => !open && setRescheduling(null)}
                title="Jadwalkan Ulang"
                fields={[
                    {
                        name: 'scheduled_at',
                        label: 'Waktu baru',
                        type: 'datetime',
                        required: true,
                    },
                    { name: 'notes', label: 'Catatan', type: 'textarea' },
                ]}
                initial={
                    rescheduling
                        ? {
                              scheduled_at: toLocalInput(
                                  rescheduling.scheduled_at,
                              ),
                              notes: rescheduling.notes,
                          }
                        : undefined
                }
                submitting={saving}
                onSubmit={reschedule}
            />
        </div>
    );
}

AppointmentsIndex.layout = {
    breadcrumbs: [{ title: 'Jadwal', href: '/appointments' }],
};
