import { useCallback, useEffect, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, ClipboardList, FileText } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { NoAccess, PageHeader } from '@/components/orp/page-header';
import { usePermissions } from '@/hooks/use-permissions';
import { ApiError, apiGet } from '@/lib/api';
import {
    ORDER_PRIORITY_COLOR,
    ORDER_PRIORITY_LABEL,
    formatDateTime,
} from '@/lib/orp-format';
import type { Order, Paginated, Study } from '@/types/orp';

export default function WorklistIndex() {
    const { can } = usePermissions();
    const [awaiting, setAwaiting] = useState<Order[]>([]);
    const [today, setToday] = useState<Order[]>([]);
    const [unmatched, setUnmatched] = useState<Study[]>([]);
    const [loading, setLoading] = useState(true);

    const load = useCallback(async () => {
        setLoading(true);

        try {
            const [a, t, u] = await Promise.all([
                apiGet<{ data: Order[] }>('/api/orders/awaiting-report'),
                apiGet<Paginated<Order>>('/api/orders?per_page=15'),
                apiGet<Paginated<Study>>(
                    '/api/studies?unmatched=1&per_page=10',
                ),
            ]);

            setAwaiting(a.data ?? []);
            setToday(t.data ?? []);
            setUnmatched(u.data ?? []);
        } catch (error) {
            toast.error(
                error instanceof ApiError
                    ? error.message
                    : 'Gagal memuat worklist',
            );
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        void load();
    }, [load]);

    if (!can('orders.view')) {
        return (
            <div className="p-4">
                <Head title="Worklist" />
                <NoAccess permission="orders.view" />
            </div>
        );
    }

    return (
        <div className="flex flex-1 flex-col gap-4 p-4">
            <Head title="Worklist" />

            <PageHeader
                title="Worklist"
                description="Antrean kerja: order menunggu report, order terbaru, dan study yang belum tercocokkan."
            />

            <div className="grid gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle className="flex items-center gap-2 text-base">
                            <FileText className="size-4" /> Menunggu Report (
                            {awaiting.length})
                        </CardTitle>
                        {can('reports.view') && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/reports">Buka Reporting</Link>
                            </Button>
                        )}
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {loading ? (
                            <p className="text-muted-foreground text-sm">
                                Memuat…
                            </p>
                        ) : awaiting.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                Tidak ada order yang menunggu report.
                            </p>
                        ) : (
                            awaiting.slice(0, 10).map((order) => (
                                <div
                                    key={order.id}
                                    className="flex items-center justify-between gap-2 rounded-md border p-3"
                                >
                                    <div className="min-w-0">
                                        <div className="truncate font-medium">
                                            {order.patient?.name ?? '—'}
                                        </div>
                                        <div className="text-muted-foreground text-xs">
                                            {order.order_number} ·{' '}
                                            {order.accession_number} ·{' '}
                                            {order.procedure?.name ?? ''}
                                        </div>
                                        <div className="text-muted-foreground text-xs">
                                            selesai{' '}
                                            {formatDateTime(order.completed_at)}
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <Badge
                                            className={
                                                ORDER_PRIORITY_COLOR[
                                                    order.priority
                                                ] ?? ''
                                            }
                                        >
                                            {ORDER_PRIORITY_LABEL[
                                                order.priority
                                            ] ?? order.priority}
                                        </Badge>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            asChild
                                        >
                                            <Link href={`/orders/${order.id}`}>
                                                Detail
                                            </Link>
                                        </Button>
                                    </div>
                                </div>
                            ))
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle className="flex items-center gap-2 text-base">
                            <ClipboardList className="size-4" /> Order Terbaru (
                            {today.length})
                        </CardTitle>
                        <Button variant="outline" size="sm" asChild>
                            <Link href="/orders">Semua Order</Link>
                        </Button>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {loading ? (
                            <p className="text-muted-foreground text-sm">
                                Memuat…
                            </p>
                        ) : today.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                Belum ada order.
                            </p>
                        ) : (
                            today.slice(0, 10).map((order) => (
                                <div
                                    key={order.id}
                                    className="flex items-center justify-between gap-2 rounded-md border p-3"
                                >
                                    <div className="min-w-0">
                                        <div className="truncate font-medium">
                                            {order.patient?.name ?? '—'}
                                        </div>
                                        <div className="text-muted-foreground text-xs">
                                            {order.order_number} ·{' '}
                                            {order.procedure?.name ?? ''}
                                        </div>
                                    </div>
                                    <Badge variant="secondary">
                                        {order.status}
                                    </Badge>
                                </div>
                            ))
                        )}
                    </CardContent>
                </Card>
            </div>

            <Card>
                <CardHeader className="flex flex-row items-center justify-between">
                    <CardTitle className="flex items-center gap-2 text-base">
                        <AlertTriangle className="size-4 text-amber-500" />{' '}
                        Study Belum Cocok ({unmatched.length})
                    </CardTitle>
                    <Button variant="outline" size="sm" asChild>
                        <Link href="/studies">Semua Study</Link>
                    </Button>
                </CardHeader>
                <CardContent className="space-y-2">
                    {loading ? (
                        <p className="text-muted-foreground text-sm">Memuat…</p>
                    ) : unmatched.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            Semua study sudah tercocokkan dengan order.
                        </p>
                    ) : (
                        unmatched.map((study) => (
                            <div
                                key={study.id}
                                className="flex items-center justify-between gap-2 rounded-md border p-3 text-sm"
                            >
                                <div>
                                    <div className="font-medium">
                                        {study.study_description ?? 'Study'}
                                    </div>
                                    <div className="text-muted-foreground text-xs">
                                        <code>
                                            {study.accession_number ?? '—'}
                                        </code>{' '}
                                        · {study.modality ?? '—'}
                                    </div>
                                </div>
                                <Button variant="outline" size="sm" asChild>
                                    <Link href="/studies">Tindak Lanjut</Link>
                                </Button>
                            </div>
                        ))
                    )}
                </CardContent>
            </Card>
        </div>
    );
}

WorklistIndex.layout = {
    breadcrumbs: [{ title: 'Worklist', href: '/worklist' }],
};
