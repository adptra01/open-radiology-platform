import { useCallback, useEffect, useState } from 'react';
import { BrainCircuit, ChevronDown, Loader2, Play } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { apiGet, apiPost } from '@/lib/api';
import type { AiCapability, AiRun, Study } from '@/types/orp';

type LegacyTb = {
    available?: boolean;
    probability?: number | null;
    label?: string | null;
    note?: string;
};

function legacyTbOf(study: Study): LegacyTb | null {
    const results = study.ai_results ?? [];
    const last = results[results.length - 1];
    const tb = last?.raw_report?.['tb'] as LegacyTb | undefined;
    return tb ?? null;
}

function runStatusLabel(status: AiRun['status']): string {
    switch (status) {
        case 'queued':
            return 'Antri';
        case 'running':
            return 'Diproses';
        case 'completed':
            return 'Selesai';
        case 'failed':
            return 'Gagal';
        case 'cancelled':
            return 'Dibatalkan';
        default:
            return status;
    }
}

function ClassificationView({ run }: { run: AiRun }) {
    const cls = run.result?.classification;
    if (!cls) return null;
    const pct = Math.round((cls.score ?? 0) * 100);
    const positive = cls.label === 'positive';
    const calibrated = run.result?.calibration?.status === 'calibrated';
    return (
        <div className="mt-2">
            <div className="flex items-center gap-2">
                <Badge
                    variant="secondary"
                    className={positive ? 'bg-red-500/80' : 'bg-emerald-500/80'}
                >
                    {positive ? 'Positif' : 'Negatif'}
                </Badge>
                <span className="text-sm font-semibold">{pct.toFixed(1)}%</span>
                {!calibrated && (
                    <span className="text-muted-foreground text-[11px]">
                        threshold {(cls.threshold ?? 0.5).toFixed(2)} · belum terkalibrasi
                    </span>
                )}
            </div>
            <div className="mt-1 h-2 overflow-hidden rounded-full bg-muted">
                <div
                    className={`h-full ${positive ? 'bg-red-500' : 'bg-emerald-500'}`}
                    style={{ width: `${pct}%` }}
                />
            </div>
            <p className="text-muted-foreground mt-1 text-[11px]">
                Model {run.model_id ?? '—'} v{run.model_version ?? '—'}
            </p>
        </div>
    );
}

function FailedView({ run }: { run: AiRun }) {
    const [open, setOpen] = useState(false);
    return (
        <div className="mt-2">
            <p className="text-sm">Tidak dapat memproses study ini.</p>
            {run.error_message && (
                <p className="text-muted-foreground mt-1 text-xs">
                    Alasan: {run.error_message}
                </p>
            )}
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                className="text-muted-foreground mt-1 flex items-center gap-1 text-[11px] underline"
            >
                View technical details
                <ChevronDown
                    className={`size-3 transition-transform ${open ? 'rotate-180' : ''}`}
                />
            </button>
            {open && (
                <pre className="mt-1 overflow-x-auto rounded bg-muted p-2 text-[11px]">
                    {run.error_code ?? 'UNKNOWN_ERROR'}
                </pre>
            )}
        </div>
    );
}

function TaskCard({
    capability,
    runs,
    studyId,
    onChanged,
}: {
    capability: AiCapability;
    runs: AiRun[];
    studyId: number;
    onChanged: () => void;
}) {
    const [starting, setStarting] = useState(false);
    const taskRuns = runs.filter((r) => r.task_id === capability.task_id);
    const latest = taskRuns[taskRuns.length - 1];
    const busy =
        starting || latest?.status === 'queued' || latest?.status === 'running';

    async function start() {
        setStarting(true);
        try {
            await apiPost<{ run_id: string }>('/api/ai/runs', {
                study_id: studyId,
                task_id: capability.task_id,
            });
            toast.success(`${capability.name} dimulai`);
            onChanged();
        } catch (error) {
            toast.error(
                error instanceof Error ? error.message : 'Gagal menjalankan AI',
            );
        } finally {
            setStarting(false);
        }
    }

    return (
        <div className="rounded-md border p-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <div className="text-sm font-medium">{capability.name}</div>
                    <div className="text-muted-foreground text-[11px]">
                        {capability.modalities.join('/')}
                        {' · '}
                        {capability.body_regions.join('/')}
                        {!capability.available && (
                            <span> · tidak tersedia: {capability.note ?? '—'}</span>
                        )}
                    </div>
                </div>
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => void start()}
                    disabled={busy || !capability.available}
                >
                    {busy ? (
                        <Loader2 className="size-3.5 animate-spin" />
                    ) : (
                        <Play className="size-3.5" />
                    )}
                    <span className="ml-1">Run AI</span>
                </Button>
            </div>

            {latest?.status === 'completed' && <ClassificationView run={latest} />}
            {latest?.status === 'failed' && <FailedView run={latest} />}
            {(latest?.status === 'queued' || latest?.status === 'running') && (
                <p className="text-muted-foreground mt-2 flex items-center gap-2 text-xs">
                    <Loader2 className="size-3.5 animate-spin" />
                    {runStatusLabel(latest.status)}… ({latest.run_id})
                </p>
            )}
        </div>
    );
}

/**
 * Panel AI Assistance generik (keputusan arsitektur final M10+).
 *
 * Capability-driven: daftar task dari GET /api/ai/capabilities, riwayat dari
 * GET /api/ai/runs?study_id=. Satu-satunya implementation nyata: tb-screening.
 * Hasil legacy (raw_report['tb']) ditampilkan di seksi "Riwayat lama" bila
 * belum ada canonical run.
 */
export function AiAssistancePanel({
    study,
    onRun,
}: {
    study: Study;
    onRun: () => void;
}) {
    const [capabilities, setCapabilities] = useState<AiCapability[]>([]);
    const [runs, setRuns] = useState<AiRun[]>([]);
    const [loaded, setLoaded] = useState(false);

    const refresh = useCallback(async () => {
        try {
            const [caps, history] = await Promise.all([
                apiGet<{ data: AiCapability[] }>('/api/ai/capabilities'),
                apiGet<{ data: AiRun[] }>(`/api/ai/runs?study_id=${study.id}&per_page=50`),
            ]);
            setCapabilities(caps.data ?? []);
            const list = Array.isArray(history)
                ? history
                : (history.data ?? []);
            setRuns(list);
        } catch {
            // Gateway mati / belum ada runs — panel tetap render legacy.
        } finally {
            setLoaded(true);
        }
    }, [study.id]);

    useEffect(() => {
        void refresh();
    }, [refresh, study.ai_results]);

    // Poll ringan selama ada run aktif.
    useEffect(() => {
        if (!runs.some((r) => r.status === 'queued' || r.status === 'running')) {
            return;
        }
        const t = setInterval(() => void refresh(), 2500);
        return () => clearInterval(t);
    }, [runs, refresh]);

    const legacy = legacyTbOf(study);
    const showLegacy = runs.length === 0 && legacy !== null;

    return (
        <div className="rounded-md bg-muted/40 p-3">
            <div className="flex items-center gap-2">
                <BrainCircuit className="text-muted-foreground size-4" />
                <span className="text-sm font-medium">AI Assistance</span>
            </div>

            <div className="mt-2 flex flex-col gap-2">
                {capabilities.map((cap) => (
                    <TaskCard
                        key={cap.task_id}
                        capability={cap}
                        runs={runs}
                        studyId={study.id}
                        onChanged={() => {
                            void refresh();
                            onRun();
                        }}
                    />
                ))}
                {loaded && capabilities.length === 0 && (
                    <p className="text-muted-foreground text-xs">
                        AI Gateway tidak terjangkau — kemampuan AI tidak dapat dimuat.
                    </p>
                )}
            </div>

            {runs.length > 0 && (
                <div className="mt-2 border-t pt-2">
                    <div className="text-muted-foreground text-[11px] font-medium">
                        Riwayat AI
                    </div>
                    {runs.map((r) => (
                        <div
                            key={r.run_id}
                            className="text-muted-foreground flex items-center justify-between gap-2 text-[11px]"
                        >
                            <code>{r.run_id}</code>
                            <span>
                                {r.task_id} · {runStatusLabel(r.status)}
                            </span>
                        </div>
                    ))}
                </div>
            )}

            {showLegacy && legacy.available && (
                <div className="mt-2 border-t pt-2">
                    <div className="text-muted-foreground text-[11px] font-medium">
                        Riwayat lama (legacy)
                    </div>
                    <p className="text-sm">
                        TB: {((legacy.probability ?? 0) * 100).toFixed(1)}%
                    </p>
                </div>
            )}

            <p className="text-muted-foreground mt-2 text-[11px]">
                Hasil AI hanya bantuan skrining, bukan diagnosis. Interpretasi
                klinis/radiologis tetap diperlukan.
            </p>
        </div>
    );
}
