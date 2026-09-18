import { Head, Link, usePage } from '@inertiajs/react';
import { ExternalLink, MonitorPlay } from 'lucide-react';
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
import { formatDate, ohifStudyUrl } from '@/lib/orp-format';
import type { Study } from '@/types/orp';

const MODALITIES = ['CR', 'DX', 'CT', 'MR', 'US', 'MG', 'RF', 'OT'];

export default function StudiesIndex() {
    const { can } = usePermissions();
    const { ohif_url } = usePage().props;
    const list = useApiList<Study>('/api/studies', { per_page: 15 });
    const unmatched = Boolean(list.params.unmatched);

    if (!can('orders.view')) {
        return (
            <div className="p-4">
                <Head title="Study" />
                <NoAccess permission="orders.view" />
            </div>
        );
    }

    const columns: Column<Study>[] = [
        {
            key: 'accession',
            header: 'Accession',
            cell: (s) => (
                <code className="text-xs">{s.accession_number ?? '—'}</code>
            ),
        },
        {
            key: 'patient',
            header: 'Pasien',
            cell: (s) => (
                <div>
                    <span className="font-medium">
                        {s.order?.patient?.name ?? '—'}
                    </span>
                    <div className="text-muted-foreground text-xs">
                        {s.order?.patient?.mrn ?? ''}
                    </div>
                </div>
            ),
        },
        {
            key: 'description',
            header: 'Pemeriksaan',
            cell: (s) => (
                <div>
                    <span>{s.study_description ?? '—'}</span>
                    <div className="text-muted-foreground text-xs">
                        {s.order?.procedure?.name ?? ''}
                    </div>
                </div>
            ),
        },
        {
            key: 'modality',
            header: 'Mod.',
            cell: (s) => <Badge variant="secondary">{s.modality ?? '—'}</Badge>,
        },
        {
            key: 'date',
            header: 'Tanggal',
            cell: (s) => formatDate(s.study_date),
        },
        {
            key: 'matched',
            header: 'Cocok',
            cell: (s) =>
                s.matched ? (
                    <Badge className="bg-emerald-500">Ya</Badge>
                ) : (
                    <Badge className="bg-amber-500">Belum</Badge>
                ),
        },
        {
            key: 'reports',
            header: 'Report',
            cell: (s) => s.reports_count ?? 0,
        },
        {
            key: 'ai',
            header: 'AI',
            cell: (s) =>
                (s.ai_results_count ?? 0) > 0 ? (
                    <Badge className="bg-blue-500">{s.ai_results_count}</Badge>
                ) : (
                    '—'
                ),
        },
        {
            key: 'actions',
            header: '',
            headClassName: 'text-right',
            className: 'text-right',
            cell: (s) => (
                <div className="flex justify-end gap-1">
                    <Button variant="outline" size="sm" asChild>
                        <Link href={`/viewer?study=${s.id}`}>
                            <MonitorPlay className="size-4" /> Viewer
                        </Link>
                    </Button>
                    <Button variant="ghost" size="icon" asChild>
                        <a
                            href={ohifStudyUrl(ohif_url, s.study_instance_uid)}
                            target="_blank"
                            rel="noreferrer"
                            title="Buka OHIF di tab baru"
                        >
                            <ExternalLink className="size-4" />
                        </a>
                    </Button>
                </div>
            ),
        },
    ];

    return (
        <div className="flex flex-1 flex-col gap-4 p-4">
            <Head title="Study" />

            <PageHeader
                title="Study"
                description="Study yang tersimpan di RIS (hasil C-STORE adapter) dan tautan ke viewer OHIF."
            />

            <Card>
                <CardHeader className="flex flex-wrap items-center justify-between gap-3">
                    <CardTitle className="text-base">Daftar Study</CardTitle>
                    <div className="flex flex-wrap items-center gap-2">
                        <select
                            className="bg-background rounded-md border px-3 py-2 text-sm"
                            value={String(list.params.modality ?? '')}
                            onChange={(e) =>
                                list.setFilter({
                                    modality: e.target.value || undefined,
                                })
                            }
                            aria-label="Filter modalitas"
                        >
                            <option value="">Semua modalitas</option>
                            {MODALITIES.map((modality) => (
                                <option key={modality} value={modality}>
                                    {modality}
                                </option>
                            ))}
                        </select>
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
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                className="size-4"
                                checked={unmatched}
                                onChange={(e) =>
                                    list.setFilter({
                                        unmatched: e.target.checked
                                            ? 1
                                            : undefined,
                                    })
                                }
                            />
                            Belum cocok
                        </label>
                        <Input
                            value={list.search}
                            onChange={(e) => list.setSearch(e.target.value)}
                            placeholder="Cari pasien / accession…"
                            className="w-52"
                            aria-label="Cari study"
                        />
                    </div>
                </CardHeader>
                <CardContent>
                    <DataTable
                        columns={columns}
                        rows={list.items}
                        rowKey={(s) => s.id}
                        loading={list.loading}
                        emptyMessage="Belum ada study."
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

StudiesIndex.layout = {
    breadcrumbs: [{ title: 'Study', href: '/studies' }],
};
