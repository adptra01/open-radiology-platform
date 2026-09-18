import { useCallback, useEffect, useState } from 'react';
import { Head } from '@inertiajs/react';
import ReportController from '@/actions/App/Http/Controllers/Api/ReportController';
import OrderController from '@/actions/App/Http/Controllers/Api/OrderController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { ApiError, apiGet, apiPost } from '@/lib/api';
import { toast } from 'sonner';

type PatientRef = { id: number; name: string } | null;
type ProcedureRef = { id: number; name: string } | null;

type OrderSummary = {
    id: number;
    order_number: string;
    accession_number: string;
    patient: PatientRef;
    procedure: ProcedureRef;
};

type ReportItem = {
    id: number;
    report_number: string;
    status: string;
    findings: string | null;
    impression: string | null;
    order: OrderSummary;
};

type Paginated<T> = { data: T[] };

const STATUS_COLOR: Record<string, string> = {
    DRAFT: 'bg-neutral-500',
    DICTATED: 'bg-amber-500',
    VERIFIED: 'bg-blue-500',
    FINAL: 'bg-emerald-500',
    CANCELLED: 'bg-red-500',
};

const NEXT_STATUS: Record<string, { target: string; label: string }> = {
    DRAFT: { target: 'DICTATED', label: 'Kirim ke Dokter' },
    DICTATED: { target: 'VERIFIED', label: 'Verifikasi' },
    VERIFIED: { target: 'FINAL', label: 'Finalkan' },
};

export default function ReportsIndex() {
    const [reports, setReports] = useState<ReportItem[]>([]);
    const [orders, setOrders] = useState<OrderSummary[]>([]);
    const [search, setSearch] = useState('');
    const [selectedOrder, setSelectedOrder] = useState('');
    const [findings, setFindings] = useState('');
    const [impression, setImpression] = useState('');
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [pendingId, setPendingId] = useState<number | null>(null);

    const loadReports = useCallback(async (term: string) => {
        setLoading(true);

        try {
            const url = ReportController.index.url({
                query: term ? { search: term } : {},
            });
            const data = await apiGet<Paginated<ReportItem>>(url);
            setReports(data.data ?? []);
        } catch (error) {
            toast.error(
                error instanceof ApiError
                    ? error.message
                    : 'Gagal memuat report',
            );
        } finally {
            setLoading(false);
        }
    }, []);

    const loadOrders = useCallback(async () => {
        try {
            const data = await apiGet<Paginated<OrderSummary>>(
                OrderController.awaitingReport.url(),
            );
            setOrders(data.data ?? []);
        } catch (error) {
            toast.error(
                error instanceof ApiError
                    ? error.message
                    : 'Gagal memuat daftar order',
            );
        }
    }, []);

    useEffect(() => {
        // Muat awal: tanpa filter, lalu sinkronkan tiap kata kunci berubah (debounce).
        const timer = setTimeout(
            () => void loadReports(search),
            search ? 300 : 0,
        );

        return () => clearTimeout(timer);
    }, [search, loadReports]);

    useEffect(() => {
        void loadOrders();
    }, [loadOrders]);

    async function createReport(e: React.FormEvent) {
        e.preventDefault();

        if (!selectedOrder) {
            return;
        }

        setSaving(true);

        try {
            await apiPost(ReportController.store.url(), {
                order_id: Number(selectedOrder),
                findings,
                impression,
            });
            toast.success('Report dibuat');
            setFindings('');
            setImpression('');
            setSelectedOrder('');
            await Promise.all([loadReports(search), loadOrders()]);
        } catch (error) {
            toast.error(
                error instanceof ApiError
                    ? error.message
                    : 'Gagal membuat report',
            );
        } finally {
            setSaving(false);
        }
    }

    async function transition(id: number, target: string) {
        setPendingId(id);

        try {
            await apiPost(ReportController.transition.url({ report: id }), {
                target,
            });
            toast.success(`Status → ${target}`);
            await loadReports(search);
        } catch (error) {
            toast.error(
                error instanceof ApiError ? error.message : 'Transisi gagal',
            );
        } finally {
            setPendingId(null);
        }
    }

    return (
        <>
            <Head title="Reporting" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-auto p-4">
                <div className="grid gap-4 lg:grid-cols-3">
                    <Card className="lg:col-span-2">
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle>Daftar Report</CardTitle>
                            <Input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Cari pasien…"
                                className="w-48"
                                aria-label="Cari report berdasarkan nama pasien"
                            />
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {loading ? (
                                <p className="text-muted-foreground text-sm">
                                    Memuat…
                                </p>
                            ) : reports.length === 0 ? (
                                <p className="text-muted-foreground text-sm">
                                    {search
                                        ? 'Tidak ada report yang cocok.'
                                        : 'Belum ada report.'}
                                </p>
                            ) : (
                                reports.map((r) => {
                                    const next = NEXT_STATUS[r.status];

                                    return (
                                        <div
                                            key={r.id}
                                            className="flex items-center justify-between gap-3 rounded-lg border p-3"
                                        >
                                            <div className="min-w-0">
                                                <div className="flex items-center gap-2">
                                                    <span className="font-medium">
                                                        {r.order?.patient
                                                            ?.name ?? '—'}
                                                    </span>
                                                    <Badge
                                                        className={
                                                            STATUS_COLOR[
                                                                r.status
                                                            ] ?? ''
                                                        }
                                                    >
                                                        {r.status}
                                                    </Badge>
                                                </div>
                                                <p className="text-muted-foreground truncate text-xs">
                                                    {r.report_number} ·{' '}
                                                    {r.order
                                                        ?.accession_number ??
                                                        ''}{' '}
                                                    ·{' '}
                                                    {r.order?.procedure?.name ??
                                                        ''}
                                                </p>
                                                {r.impression && (
                                                    <p className="mt-1 text-sm italic">
                                                        "{r.impression}"
                                                    </p>
                                                )}
                                            </div>
                                            {next && (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    disabled={
                                                        pendingId === r.id
                                                    }
                                                    onClick={() =>
                                                        transition(
                                                            r.id,
                                                            next.target,
                                                        )
                                                    }
                                                >
                                                    {pendingId === r.id
                                                        ? 'Menyimpan…'
                                                        : next.label}
                                                </Button>
                                            )}
                                        </div>
                                    );
                                })
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Buat Report Baru</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={createReport} className="space-y-3">
                                <div className="space-y-1">
                                    <Label htmlFor="order">
                                        Order (COMPLETED, belum FINAL)
                                    </Label>
                                    <select
                                        id="order"
                                        value={selectedOrder}
                                        onChange={(e) =>
                                            setSelectedOrder(e.target.value)
                                        }
                                        className="bg-background w-full rounded-md border px-3 py-2 text-sm"
                                    >
                                        <option value="">
                                            — pilih order —
                                        </option>
                                        {orders.map((o) => (
                                            <option key={o.id} value={o.id}>
                                                {o.order_number} ·{' '}
                                                {o.patient?.name} ·{' '}
                                                {o.procedure?.name}
                                            </option>
                                        ))}
                                    </select>
                                    {orders.length === 0 && (
                                        <p className="text-muted-foreground text-xs">
                                            Tidak ada order yang menunggu
                                            report.
                                        </p>
                                    )}
                                </div>
                                <div className="space-y-1">
                                    <Label htmlFor="findings">Findings</Label>
                                    <Textarea
                                        id="findings"
                                        value={findings}
                                        onChange={(e) =>
                                            setFindings(e.target.value)
                                        }
                                        rows={4}
                                    />
                                </div>
                                <div className="space-y-1">
                                    <Label htmlFor="impression">
                                        Impression / Kesan
                                    </Label>
                                    <Textarea
                                        id="impression"
                                        value={impression}
                                        onChange={(e) =>
                                            setImpression(e.target.value)
                                        }
                                        rows={3}
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    disabled={!selectedOrder || saving}
                                    className="w-full"
                                >
                                    {saving ? 'Menyimpan…' : 'Simpan DRAFT'}
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}

ReportsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Reporting',
            href: '/reports',
        },
    ],
};
