import { useCallback, useEffect, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import { Ban, ExternalLink, MonitorPlay } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { AiTbCard } from '@/components/orp/ai-tb-card';
import { AiAssistancePanel } from '@/components/orp/ai-assistance-panel';
import { ConfirmButton } from '@/components/orp/confirm-button';
import { NoAccess, PageHeader } from '@/components/orp/page-header';
import { usePermissions } from '@/hooks/use-permissions';
import { usePage } from '@inertiajs/react';
import { ApiError, apiGet, apiPost } from '@/lib/api';
import {
    ORDER_PRIORITY_COLOR,
    ORDER_PRIORITY_LABEL,
    ORDER_STATUS_COLOR,
    ORDER_STATUS_LABEL,
    REPORT_STATUS_COLOR,
    REPORT_STATUS_LABEL,
    formatDateTime,
    ohifStudyUrl,
} from '@/lib/orp-format';
import type { Order } from '@/types/orp';

function Field({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div>
            <div className="text-muted-foreground text-xs">{label}</div>
            <div className="text-sm">{value}</div>
        </div>
    );
}

export default function OrderDetail() {
    const { can } = usePermissions();
    const { ohif_url } = usePage().props;
    const [order, setOrder] = useState<Order | null>(null);
    const [loading, setLoading] = useState(true);

    const orderId = Number(
        window.location.pathname.split('/').filter(Boolean).pop(),
    );

    const load = useCallback(async () => {
        setLoading(true);

        try {
            const data = await apiGet<Order>(`/api/orders/${orderId}`);
            setOrder(data);
        } catch (error) {
            toast.error(
                error instanceof ApiError
                    ? error.message
                    : 'Gagal memuat order',
            );
        } finally {
            setLoading(false);
        }
    }, [orderId]);

    useEffect(() => {
        if (Number.isFinite(orderId) && orderId > 0) {
            void load();
        }
    }, [load, orderId]);

    if (!can('orders.view')) {
        return (
            <div className="p-4">
                <Head title="Detail Order" />
                <NoAccess permission="orders.view" />
            </div>
        );
    }

    async function cancel() {
        try {
            await apiPost(`/api/orders/${orderId}/cancel`);
            toast.success('Order dibatalkan');
            await load();
        } catch (error) {
            toast.error(
                error instanceof ApiError
                    ? error.message
                    : 'Gagal membatalkan order',
            );
        }
    }

    return (
        <div className="flex flex-1 flex-col gap-4 p-4">
            <Head
                title={order ? `Order ${order.order_number}` : 'Detail Order'}
            />

            {loading || !order ? (
                <p className="text-muted-foreground text-sm">
                    {loading ? 'Memuat…' : 'Order tidak ditemukan.'}
                </p>
            ) : (
                <>
                    <PageHeader
                        title={`Order ${order.order_number}`}
                        description={`Accession ${order.accession_number} · diminta ${formatDateTime(order.requested_at)}`}
                        actions={
                            <>
                                {can('reports.view') && (
                                    <Button variant="outline" asChild>
                                        <Link href="/reports">Reporting</Link>
                                    </Button>
                                )}
                                {can('orders.cancel') &&
                                    !['CANCELLED', 'COMPLETED'].includes(
                                        order.status,
                                    ) && (
                                        <ConfirmButton
                                            title="Batalkan order?"
                                            description="Order akan berstatus CANCELLED dan tidak bisa dikembalikan."
                                            confirmLabel="Batalkan"
                                            trigger={
                                                <Button variant="destructive">
                                                    <Ban className="size-4" />{' '}
                                                    Batalkan
                                                </Button>
                                            }
                                            onConfirm={cancel}
                                        />
                                    )}
                            </>
                        }
                    />

                    <div className="flex flex-wrap items-center gap-2">
                        <Badge
                            className={ORDER_STATUS_COLOR[order.status] ?? ''}
                        >
                            {ORDER_STATUS_LABEL[order.status] ?? order.status}
                        </Badge>
                        <Badge
                            className={
                                ORDER_PRIORITY_COLOR[order.priority] ?? ''
                            }
                        >
                            {ORDER_PRIORITY_LABEL[order.priority] ??
                                order.priority}
                        </Badge>
                    </div>

                    <div className="grid gap-4 md:grid-cols-2">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Pasien
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="grid grid-cols-2 gap-3">
                                <Field
                                    label="Nama"
                                    value={order.patient?.name ?? '—'}
                                />
                                <Field
                                    label="MRN"
                                    value={order.patient?.mrn ?? '—'}
                                />
                                <Field
                                    label="Telepon"
                                    value={order.patient?.phone ?? '—'}
                                />
                                <Field
                                    label="Tgl lahir"
                                    value={order.patient?.birth_date ?? '—'}
                                />
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Pemeriksaan
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="grid grid-cols-2 gap-3">
                                <Field
                                    label="Prosedur"
                                    value={order.procedure?.name ?? '—'}
                                />
                                <Field
                                    label="Kode"
                                    value={order.procedure?.code ?? '—'}
                                />
                                <Field
                                    label="Modalitas"
                                    value={
                                        order.modality?.name ??
                                        order.procedure?.modality ??
                                        '—'
                                    }
                                />
                                <Field
                                    label="Dokter perujuk"
                                    value={order.referring_doctor?.name ?? '—'}
                                />
                                <Field
                                    label="Jadwal"
                                    value={formatDateTime(order.scheduled_at)}
                                />
                                <Field
                                    label="Selesai"
                                    value={formatDateTime(order.completed_at)}
                                />
                                {order.status_note && (
                                    <div className="col-span-2">
                                        <Field
                                            label="Catatan"
                                            value={order.status_note}
                                        />
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Study ({order.studies?.length ?? 0})
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {(order.studies ?? []).length === 0 ? (
                                <p className="text-muted-foreground text-sm">
                                    Belum ada study diterima (C-STORE).
                                </p>
                            ) : (
                                (order.studies ?? []).map((study) => (
                                    <div
                                        key={study.id}
                                        className="flex flex-wrap items-center justify-between gap-2 rounded-md border p-3"
                                    >
                                        <div>
                                            <div className="font-medium">
                                                {study.study_description ??
                                                    'Study'}
                                            </div>
                                            <div className="text-muted-foreground text-xs">
                                                <code>
                                                    {study.study_instance_uid}
                                                </code>
                                                {' · '}
                                                {study.modality ?? '—'}
                                                {' · '}
                                                {study.study_date ?? '—'}
                                            </div>
                                        </div>
                                        <div className="flex w-full items-center gap-2">
                                            <AiAssistancePanel study={study} onRun={() => void load()} />
                                        </div>
                                        <div className="flex w-full items-center gap-2">
                                            <AiTbCard study={study} onRun={() => void load()} />
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <Badge
                                                variant="secondary"
                                                className={
                                                    study.matched
                                                        ? 'bg-emerald-500/80'
                                                        : ''
                                                }
                                            >
                                                {study.matched
                                                    ? 'Cocok'
                                                    : 'Belum cocok'}
                                            </Badge>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                asChild
                                            >
                                                <Link
                                                    href={`/viewer?study=${study.id}`}
                                                >
                                                    <MonitorPlay className="size-4" />
                                                    Viewer
                                                </Link>
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                asChild
                                            >
                                                <a
                                                    href={ohifStudyUrl(
                                                        ohif_url,
                                                        study.study_instance_uid,
                                                    )}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    title="Buka OHIF di tab baru"
                                                >
                                                    <ExternalLink className="size-4" />
                                                </a>
                                            </Button>
                                        </div>
                                    </div>
                                ))
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Report ({order.reports?.length ?? 0})
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {(order.reports ?? []).length === 0 ? (
                                <p className="text-muted-foreground text-sm">
                                    Belum ada report.
                                </p>
                            ) : (
                                (order.reports ?? []).map((report) => (
                                    <div
                                        key={report.id}
                                        className="rounded-md border p-3"
                                    >
                                        <div className="flex items-center gap-2">
                                            <span className="font-medium">
                                                {report.report_number}
                                            </span>
                                            <Badge
                                                className={
                                                    REPORT_STATUS_COLOR[
                                                        report.status
                                                    ] ?? ''
                                                }
                                            >
                                                {REPORT_STATUS_LABEL[
                                                    report.status
                                                ] ?? report.status}
                                            </Badge>
                                        </div>
                                        {report.impression && (
                                            <p className="mt-1 text-sm italic">
                                                “{report.impression}”
                                            </p>
                                        )}
                                        <p className="text-muted-foreground text-xs">
                                            {report.radiologist?.name ??
                                                'radiolog belum ditetapkan'}
                                        </p>
                                    </div>
                                ))
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Jadwal ({order.appointments?.length ?? 0})
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {(order.appointments ?? []).length === 0 ? (
                                <p className="text-muted-foreground text-sm">
                                    Belum ada jadwal.
                                </p>
                            ) : (
                                (order.appointments ?? []).map(
                                    (appointment) => (
                                        <div
                                            key={appointment.id}
                                            className="flex items-center justify-between rounded-md border p-3 text-sm"
                                        >
                                            <span>
                                                {formatDateTime(
                                                    appointment.scheduled_at,
                                                )}
                                            </span>
                                            <Badge variant="secondary">
                                                {appointment.status}
                                            </Badge>
                                        </div>
                                    ),
                                )
                            )}
                        </CardContent>
                    </Card>
                </>
            )}
        </div>
    );
}

OrderDetail.layout = {
    breadcrumbs: [{ title: 'Order', href: '/orders' }],
};
