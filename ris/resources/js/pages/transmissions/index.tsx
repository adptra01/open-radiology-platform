import { Head } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
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
import { useApiList } from '@/hooks/use-api-list';
import { usePermissions } from '@/hooks/use-permissions';
import { ApiError, apiPost } from '@/lib/api';
import {
    TRANSMISSION_STATUS_COLOR,
    TRANSMISSION_STATUS_LABEL,
    formatDateTime,
} from '@/lib/orp-format';
import type { Transmission } from '@/types/orp';
import { useState } from 'react';

const STATUS_OPTIONS = Object.keys(TRANSMISSION_STATUS_LABEL);

export default function TransmissionsIndex() {
    const { can } = usePermissions();
    const list = useApiList<Transmission>('/api/transmissions', {
        per_page: 15,
    });
    const [pendingId, setPendingId] = useState<number | null>(null);

    if (!can('transmission.view')) {
        return (
            <div className="p-4">
                <Head title="Transmisi" />
                <NoAccess permission="transmission.view" />
            </div>
        );
    }

    async function retry(transmission: Transmission) {
        setPendingId(transmission.id);

        try {
            await apiPost(`/api/transmissions/${transmission.id}/retry`);
            toast.success('Transmisi dikirim ulang');
            list.reload();
        } catch (error) {
            toast.error(
                error instanceof ApiError
                    ? error.message
                    : 'Gagal mengirim ulang',
            );
        } finally {
            setPendingId(null);
        }
    }

    const columns: Column<Transmission>[] = [
        {
            key: 'id',
            header: '#',
            cell: (t) => <span className="text-muted-foreground">#{t.id}</span>,
        },
        {
            key: 'status',
            header: 'Status',
            cell: (t) => (
                <Badge className={TRANSMISSION_STATUS_COLOR[t.status] ?? ''}>
                    {TRANSMISSION_STATUS_LABEL[t.status] ?? t.status}
                </Badge>
            ),
        },
        { key: 'type', header: 'Tipe', cell: (t) => t.transmission_type },
        {
            key: 'patient',
            header: 'Pasien',
            cell: (t) => t.study?.order?.patient?.name ?? '—',
        },
        {
            key: 'accession',
            header: 'Accession',
            cell: (t) => (
                <code className="text-xs">
                    {t.study?.accession_number ?? t.order_id ?? '—'}
                </code>
            ),
        },
        {
            key: 'pacs',
            header: 'Tujuan',
            cell: (t) => t.pacs_source?.name ?? '—',
        },
        {
            key: 'attempts',
            header: 'Percobaan',
            cell: (t) => `${t.attempts}/${t.max_attempts ?? '—'}`,
        },
        {
            key: 'sent',
            header: 'Terkirim',
            cell: (t) => formatDateTime(t.sent_at ?? t.completed_at),
        },
        {
            key: 'error',
            header: 'Error',
            cell: (t) =>
                t.error ? (
                    <span className="text-xs text-red-500" title={t.error}>
                        {t.error.length > 40
                            ? `${t.error.slice(0, 40)}…`
                            : t.error}
                    </span>
                ) : (
                    '—'
                ),
        },
        {
            key: 'actions',
            header: '',
            headClassName: 'text-right',
            className: 'text-right',
            cell: (t) => (
                <div className="flex justify-end">
                    {can('transmission.retry') &&
                        ['FAILED', 'CANCELLED'].includes(t.status) && (
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={pendingId === t.id}
                                onClick={() => void retry(t)}
                            >
                                <RefreshCw className="size-4" />
                                {pendingId === t.id
                                    ? 'Mengirim…'
                                    : 'Kirim ulang'}
                            </Button>
                        )}
                </div>
            ),
        },
    ];

    return (
        <div className="flex flex-1 flex-col gap-4 p-4">
            <Head title="Transmisi" />

            <PageHeader
                title="Transmisi"
                description="Antrean pengiriman study ke PACS (STOW-RS)."
            />

            <Card>
                <CardHeader className="flex flex-row items-center justify-between gap-3">
                    <CardTitle className="text-base">Antrean</CardTitle>
                    <div className="flex items-center gap-2">
                        <select
                            className="bg-background rounded-md border px-3 py-2 text-sm"
                            value={String(list.params.status ?? '')}
                            onChange={(e) =>
                                list.setFilter({
                                    status: e.target.value || undefined,
                                })
                            }
                            aria-label="Filter status transmisi"
                        >
                            <option value="">Semua status</option>
                            {STATUS_OPTIONS.map((status) => (
                                <option key={status} value={status}>
                                    {TRANSMISSION_STATUS_LABEL[status]}
                                </option>
                            ))}
                        </select>
                        <Input
                            value={list.search}
                            onChange={(e) => list.setSearch(e.target.value)}
                            placeholder="Cari pasien / accession…"
                            className="w-56"
                            aria-label="Cari transmisi"
                        />
                    </div>
                </CardHeader>
                <CardContent>
                    <DataTable
                        columns={columns}
                        rows={list.items}
                        rowKey={(t) => t.id}
                        loading={list.loading}
                        emptyMessage="Belum ada transmisi."
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
        </div>
    );
}

TransmissionsIndex.layout = {
    breadcrumbs: [{ title: 'Transmisi', href: '/transmissions' }],
};
