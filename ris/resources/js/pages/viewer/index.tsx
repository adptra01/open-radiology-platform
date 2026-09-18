import { useCallback, useEffect, useState } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import { ExternalLink, RefreshCcw } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { NoAccess, PageHeader } from '@/components/orp/page-header';
import { usePermissions } from '@/hooks/use-permissions';
import { ApiError, apiGet } from '@/lib/api';
import { formatDate, ohifStudyUrl } from '@/lib/orp-format';
import type { Study } from '@/types/orp';

/**
 * Embed viewer OHIF di dalam RIS.
 *
 * Study dipilih lewat query string (`/viewer?study=<id>`), bukan route param,
 * karena `Route::inertia` tidak mengoper parameter route ke props halaman.
 * Viewer sendiri menunjuk ke URL OHIF dari prop bersama `ohif_url`
 * (config `ris.viewer.ohif_url` → `ORP_OHIF_URL`).
 */
export default function ViewerIndex() {
    const { can } = usePermissions();
    const ohifUrl = usePage().props.ohif_url;
    const [studyId, setStudyId] = useState<string | null>(null);
    const [study, setStudy] = useState<Study | null>(null);
    const [loading, setLoading] = useState(true);
    const [reloadKey, setReloadKey] = useState(0);

    useEffect(() => {
        setStudyId(
            new URLSearchParams(window.location.search).get('study') ?? null,
        );
    }, []);

    const load = useCallback(async (id: string) => {
        setLoading(true);

        try {
            const res = await apiGet<{ study: Study }>(`/api/studies/${id}`);
            setStudy(res.study ?? null);
        } catch (error) {
            toast.error(
                error instanceof ApiError
                    ? error.message
                    : 'Gagal memuat study',
            );
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        if (studyId) {
            void load(studyId);
        }
    }, [studyId, load]);

    if (!can('orders.view')) {
        return (
            <div className="p-4">
                <Head title="Viewer" />
                <NoAccess permission="orders.view" />
            </div>
        );
    }

    const uid = study?.study_instance_uid ?? null;
    const viewerUrl = uid ? ohifStudyUrl(ohifUrl, uid) : null;

    return (
        <div className="flex flex-1 flex-col gap-4 p-4">
            <Head title="Viewer" />

            <PageHeader
                title={
                    study
                        ? `Viewer — ${study.study_description ?? 'Study'}`
                        : 'Viewer'
                }
                description={
                    study?.order?.patient
                        ? `${study.order.patient.name} · ${study.order.patient.mrn} · ${study.accession_number ?? '—'}`
                        : 'Viewer DICOM tertanam (OHIF).'
                }
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        {study && (
                            <>
                                <Badge variant="secondary">
                                    {study.modality ?? '—'}
                                </Badge>
                                <Badge variant="outline">
                                    {formatDate(study.study_date)}
                                </Badge>
                            </>
                        )}
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setReloadKey((k) => k + 1)}
                            disabled={!viewerUrl}
                        >
                            <RefreshCcw className="size-4" /> Muat Ulang
                        </Button>
                        {viewerUrl && (
                            <Button variant="outline" size="sm" asChild>
                                <a
                                    href={viewerUrl}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <ExternalLink className="size-4" /> Tab Baru
                                </a>
                            </Button>
                        )}
                        {study?.order_id && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={`/orders/${study.order_id}`}>
                                    Ke Order
                                </Link>
                            </Button>
                        )}
                    </div>
                }
            />

            {loading ? (
                <p className="text-muted-foreground text-sm">Memuat study…</p>
            ) : !studyId ? (
                <p className="text-muted-foreground text-sm">
                    Parameter <code>?study=&lt;id&gt;</code> tidak ada. Buka
                    viewer dari halaman Study atau Order.
                </p>
            ) : !uid ? (
                <p className="text-muted-foreground text-sm">
                    Study tidak ditemukan atau tidak punya StudyInstanceUID.
                </p>
            ) : (
                <>
                    <iframe
                        key={`${uid}-${reloadKey}`}
                        src={viewerUrl ?? undefined}
                        title="OHIF Viewer"
                        className="min-h-[70vh] w-full flex-1 rounded-md border"
                        allow="fullscreen"
                    />
                    <p className="text-muted-foreground text-xs">
                        Viewer memuat DICOMweb dari{' '}
                        <code>{ohifUrl ?? '—'}</code>. Bila kosong, pastikan
                        stack vendor (<code>docker compose up</code>) berjalan
                        dan <code>ORP_OHIF_URL</code> benar.
                    </p>
                </>
            )}
        </div>
    );
}

ViewerIndex.layout = {
    breadcrumbs: [
        { title: 'Studies', href: '/studies' },
        { title: 'Viewer', href: '/viewer' },
    ],
};
