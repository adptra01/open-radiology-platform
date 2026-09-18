import { useCallback, useEffect, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { PageHeader } from '@/components/orp/page-header';
import { ApiError, apiGet } from '@/lib/api';
import {
    ORDER_STATUS_COLOR,
    ORDER_STATUS_LABEL,
    REPORT_STATUS_COLOR,
    REPORT_STATUS_LABEL,
    TRANSMISSION_STATUS_COLOR,
    TRANSMISSION_STATUS_LABEL,
    formatDateTime,
} from '@/lib/orp-format';
import type { DashboardSections } from '@/types/orp';

function StatCard({
    title,
    value,
    hint,
    href,
}: {
    title: string;
    value: string | number;
    hint?: string;
    href?: string;
}) {
    const body = (
        <Card className={href ? 'hover:border-primary/50 transition' : ''}>
            <CardContent className="py-4">
                <div className="text-muted-foreground text-xs">{title}</div>
                <div className="text-2xl font-semibold">{value}</div>
                {hint && (
                    <div className="text-muted-foreground text-xs">{hint}</div>
                )}
            </CardContent>
        </Card>
    );

    return href ? <Link href={href}>{body}</Link> : body;
}

function StatusBreakdown({
    data,
    colors,
    labels,
}: {
    data: Record<string, number>;
    colors: Record<string, string>;
    labels: Record<string, string>;
}) {
    const entries = Object.entries(data ?? {});

    if (entries.length === 0) {
        return <p className="text-muted-foreground text-sm">Belum ada data.</p>;
    }

    return (
        <div className="flex flex-wrap gap-2">
            {entries.map(([status, total]) => (
                <Badge key={status} className={colors[status] ?? ''}>
                    {labels[status] ?? status}: {total}
                </Badge>
            ))}
        </div>
    );
}

export default function Dashboard() {
    const [sections, setSections] = useState<DashboardSections | null>(null);
    const [loading, setLoading] = useState(true);

    const load = useCallback(async () => {
        try {
            const res = await apiGet<{ data: DashboardSections }>(
                '/api/dashboard',
            );
            setSections(res.data ?? {});
        } catch (error) {
            toast.error(
                error instanceof ApiError
                    ? error.message
                    : 'Gagal memuat dashboard',
            );
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        void load();
    }, [load]);

    const workflow = sections?.workflow;
    const scheduling = sections?.scheduling;
    const reports = sections?.reports;
    const pacs = sections?.pacs;
    const patients = sections?.patients;
    const ai = sections?.ai;

    return (
        <div className="flex flex-1 flex-col gap-4 p-4">
            <Head title="Dashboard" />

            <PageHeader
                title="Dashboard"
                description="Ringkasan operasional radiologi — hanya bagian yang boleh Anda akses yang ditampilkan."
            />

            {loading ? (
                <p className="text-muted-foreground text-sm">Memuat…</p>
            ) : (
                <>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {workflow && (
                            <>
                                <StatCard
                                    title="Menunggu Report"
                                    value={workflow.awaiting_report}
                                    href="/worklist"
                                />
                                <StatCard
                                    title="Study Hari Ini"
                                    value={workflow.studies_today}
                                    href="/studies"
                                />
                                <StatCard
                                    title="Study Belum Cocok"
                                    value={workflow.unmatched_studies}
                                    href="/studies"
                                />
                            </>
                        )}
                        {patients && (
                            <StatCard
                                title="Total Pasien"
                                value={patients.total}
                                hint={`${patients.registered_today} baru hari ini`}
                                href="/patients"
                            />
                        )}
                        {scheduling && (
                            <StatCard
                                title="Jadwal Hari Ini"
                                value={Object.values(
                                    scheduling.appointments_today ?? {},
                                ).reduce((sum, n) => sum + n, 0)}
                                href="/appointments"
                            />
                        )}
                        {reports && (
                            <StatCard
                                title="Draft Saya"
                                value={reports.my_draft_reports}
                                href="/reports"
                            />
                        )}
                        {pacs && (
                            <StatCard
                                title="Transmisi Menunggu"
                                value={
                                    pacs.transmissions_by_status?.PENDING ?? 0
                                }
                                hint={`Gagal: ${pacs.transmissions_by_status?.FAILED ?? 0}`}
                                href="/transmissions"
                            />
                        )}
                    </div>

                    <div className="grid gap-4 lg:grid-cols-2">
                        {workflow && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        Order per Status
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <StatusBreakdown
                                        data={workflow.orders_by_status}
                                        colors={ORDER_STATUS_COLOR}
                                        labels={ORDER_STATUS_LABEL}
                                    />
                                </CardContent>
                            </Card>
                        )}

                        {reports && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        Report per Status
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <StatusBreakdown
                                        data={reports.reports_by_status}
                                        colors={REPORT_STATUS_COLOR}
                                        labels={REPORT_STATUS_LABEL}
                                    />
                                </CardContent>
                            </Card>
                        )}

                        {pacs && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        Transmisi per Status
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <StatusBreakdown
                                        data={pacs.transmissions_by_status}
                                        colors={TRANSMISSION_STATUS_COLOR}
                                        labels={TRANSMISSION_STATUS_LABEL}
                                    />
                                </CardContent>
                            </Card>
                        )}

                        {ai && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        Inferensi AI
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <StatusBreakdown
                                        data={ai.results_by_status}
                                        colors={{
                                            COMPLETED: 'bg-emerald-500',
                                            PENDING: 'bg-amber-500',
                                            PROCESSING: 'bg-blue-500',
                                            FAILED: 'bg-red-600',
                                        }}
                                        labels={{
                                            COMPLETED: 'Selesai',
                                            PENDING: 'Menunggu',
                                            PROCESSING: 'Berjalan',
                                            FAILED: 'Gagal',
                                        }}
                                    />
                                </CardContent>
                            </Card>
                        )}
                    </div>

                    {workflow?.recent_orders &&
                        workflow.recent_orders.length > 0 && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">
                                        Order Terbaru
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-2">
                                    {workflow.recent_orders.map((order) => (
                                        <Link
                                            key={order.id}
                                            href={`/orders/${order.id}`}
                                            className="hover:bg-muted/40 flex items-center justify-between gap-3 rounded-md border p-3"
                                        >
                                            <div className="min-w-0">
                                                <div className="truncate font-medium">
                                                    {order.patient?.name ?? '—'}
                                                </div>
                                                <div className="text-muted-foreground text-xs">
                                                    {order.order_number} ·{' '}
                                                    {order.procedure?.name ??
                                                        ''}{' '}
                                                    ·{' '}
                                                    {formatDateTime(
                                                        order.requested_at,
                                                    )}
                                                </div>
                                            </div>
                                            <Badge
                                                className={
                                                    ORDER_STATUS_COLOR[
                                                        order.status
                                                    ] ?? ''
                                                }
                                            >
                                                {ORDER_STATUS_LABEL[
                                                    order.status
                                                ] ?? order.status}
                                            </Badge>
                                        </Link>
                                    ))}
                                </CardContent>
                            </Card>
                        )}

                    {sections && Object.keys(sections).length === 0 && (
                        <p className="text-muted-foreground text-sm">
                            Akun Anda tidak punya izin operasional apa pun —
                            hubungi Admin.
                        </p>
                    )}
                </>
            )}
        </div>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }],
};
