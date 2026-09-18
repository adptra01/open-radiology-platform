import { Head } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
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
import { formatDateTime } from '@/lib/orp-format';
import type { AuditLog } from '@/types/orp';

const ACTION_COLOR: Record<string, string> = {
    created: 'bg-emerald-500',
    updated: 'bg-blue-500',
    deleted: 'bg-red-500',
    merged: 'bg-purple-500',
    status: 'bg-amber-500',
    retried: 'bg-cyan-500',
};

function actionColor(action: string): string {
    const suffix = action.split('.').pop() ?? '';

    return ACTION_COLOR[suffix] ?? 'bg-slate-500';
}

export default function AuditIndex() {
    const { can } = usePermissions();
    const list = useApiList<AuditLog>('/api/audit-logs', { per_page: 25 });

    if (!can('audit.view')) {
        return (
            <div className="p-4">
                <Head title="Audit" />
                <NoAccess permission="audit.view" />
            </div>
        );
    }

    const columns: Column<AuditLog>[] = [
        {
            key: 'time',
            header: 'Waktu',
            cell: (log) => (
                <span className="whitespace-nowrap">
                    {formatDateTime(log.created_at)}
                </span>
            ),
        },
        {
            key: 'user',
            header: 'User',
            cell: (log) => log.user?.name ?? 'sistem',
        },
        {
            key: 'action',
            header: 'Aksi',
            cell: (log) => (
                <Badge className={actionColor(log.action)}>{log.action}</Badge>
            ),
        },
        {
            key: 'subject',
            header: 'Objek',
            cell: (log) =>
                log.auditable_type
                    ? `${log.auditable_type.split('\\').pop()} #${log.auditable_id}`
                    : '—',
        },
        {
            key: 'changes',
            header: 'Perubahan',
            cell: (log) => {
                if (!log.changes) {
                    return '—';
                }

                const text = JSON.stringify(log.changes);

                return (
                    <code className="text-xs" title={text}>
                        {text.length > 70 ? `${text.slice(0, 70)}…` : text}
                    </code>
                );
            },
        },
        {
            key: 'ip',
            header: 'IP',
            cell: (log) => (
                <span className="text-muted-foreground text-xs">
                    {log.ip_address ?? '—'}
                </span>
            ),
        },
    ];

    return (
        <div className="flex flex-1 flex-col gap-4 p-4">
            <Head title="Audit" />

            <PageHeader
                title="Audit Trail"
                description="Jejak aktivitas sistem: siapa, kapan, aksi apa, pada objek apa."
            />

            <Card>
                <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3">
                    <CardTitle className="text-base">Log Aktivitas</CardTitle>
                    <div className="flex flex-wrap items-center gap-2">
                        <Input
                            value={String(list.params.action ?? '')}
                            onChange={(e) =>
                                list.setFilter({
                                    action: e.target.value || undefined,
                                })
                            }
                            placeholder="Filter aksi (mis. report)"
                            className="w-48"
                            aria-label="Filter aksi audit"
                        />
                        <Input
                            type="date"
                            value={String(list.params.from ?? '')}
                            onChange={(e) =>
                                list.setFilter({
                                    from: e.target.value || undefined,
                                })
                            }
                            className="w-40"
                            aria-label="Dari tanggal"
                        />
                        <Input
                            type="date"
                            value={String(list.params.to ?? '')}
                            onChange={(e) =>
                                list.setFilter({
                                    to: e.target.value || undefined,
                                })
                            }
                            className="w-40"
                            aria-label="Sampai tanggal"
                        />
                        <Input
                            value={list.search}
                            onChange={(e) => list.setSearch(e.target.value)}
                            placeholder="Cari…"
                            className="w-48"
                            aria-label="Cari audit"
                        />
                    </div>
                </CardHeader>
                <CardContent>
                    <DataTable
                        columns={columns}
                        rows={list.items}
                        rowKey={(log) => log.id}
                        loading={list.loading}
                        emptyMessage="Belum ada aktivitas tercatat."
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

AuditIndex.layout = {
    breadcrumbs: [{ title: 'Audit', href: '/audit' }],
};
