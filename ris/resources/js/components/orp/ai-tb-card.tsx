import { useState } from 'react';
import { Loader2, ScanSearch } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { apiPost } from '@/lib/api';
import type { Study } from '@/types/orp';

type AiResult = NonNullable<Study['ai_results']>[number];

type TbPayload = {
    available?: boolean;
    probability?: number | null;
    note?: string;
};

/** Ambil payload TB dari raw_report (skema: raw_report.tb = {available, probability, note}). */
function tbOf(result: AiResult | undefined): TbPayload | null {
    const raw = result?.raw_report;
    const tb = raw?.['tb'] as TbPayload | undefined;
    return tb ?? null;
}

function statusLabel(status: string): string {
    switch (status) {
        case 'PENDING':
            return 'Antri';
        case 'PROCESSING':
            return 'Diproses';
        case 'COMPLETED':
            return 'Selesai';
        case 'FAILED':
            return 'Gagal';
        default:
            return status;
    }
}

/**
 * Kartu skrining TB AI per study (M8).
 *
 * Menampilkan hasil ai-result yang di-dispatch backend (RunAiInference)
 * dan tombol untuk menjalankan/mengulang inferensi:
 *
 *   PENDING / PROCESSING -> status berjalan
 *   FAILED               -> pesan error + tombol ulangi
 *   COMPLETED            -> probabilitas TB (triase) atau "model belum tersedia"
 *
 * Disclaimer wajib: alat skrining, bukan pengganti diagnosis radiolog.
 */
export function AiTbCard({ study, onRun }: { study: Study; onRun: () => void }) {
    const [running, setRunning] = useState(false);

    // Tanpa ordering eksplisit dari API -> ambil entri terakhir sebagai hasil aktif.
    const results = study.ai_results ?? [];
    const last: AiResult | undefined = results[results.length - 1];
    const status = last?.status;
    const tb = tbOf(last);
    const busy = running || status === 'PENDING' || status === 'PROCESSING';

    async function run() {
        setRunning(true);

        try {
            await apiPost(`/api/ai-results/run/${study.id}`);
            toast.success('Skrining TB dimulai');
            onRun();
        } catch (error) {
            toast.error(
                error instanceof Error ? error.message : 'Gagal menjalankan skrining TB',
            );
        } finally {
            setRunning(false);
        }
    }

    return (
        <div className="rounded-md bg-muted/40 p-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex items-center gap-2">
                    <ScanSearch className="text-muted-foreground size-4" />
                    <span className="text-sm font-medium">Skrining TB (AI)</span>
                </div>
                {status ? (
                    <Badge
                        variant="secondary"
                        className={
                            status === 'FAILED'
                                ? 'bg-red-500/80'
                                : status === 'COMPLETED'
                                  ? 'bg-emerald-500/80'
                                  : 'bg-amber-500/80'
                        }
                    >
                        {statusLabel(status)}
                    </Badge>
                ) : null}
            </div>

            {status === 'COMPLETED' && tb ? (
                tb.available ? (
                    <div className="mt-2">
                        <div className="text-muted-foreground text-xs">
                            Probabilitas TB
                        </div>
                        <div className="mt-1 flex items-center gap-2">
                            <div className="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                                <div
                                    className="h-full bg-red-500"
                                    style={{
                                        width: `${Math.round(
                                            (tb.probability ?? 0) * 100,
                                        )}%`,
                                    }}
                                />
                            </div>
                            <span className="text-sm font-semibold">
                                {((tb.probability ?? 0) * 100).toFixed(1)}%
                            </span>
                        </div>
                    </div>
                ) : (
                    <p className="text-muted-foreground mt-2 text-xs">
                        {tb.note ??
                            'Model TB belum tersedia di worker (bobot fine-tune belum ada).'}
                    </p>
                )
            ) : status === 'FAILED' ? (
                <p className="mt-2 text-xs text-red-600">
                    {last?.error ?? 'Inferensi gagal.'}
                </p>
            ) : busy ? (
                <p className="text-muted-foreground mt-2 flex items-center gap-2 text-xs">
                    <Loader2 className="size-3.5 animate-spin" />
                    Menjalankan inferensi…
                </p>
            ) : (
                <p className="text-muted-foreground mt-2 text-xs">
                    Belum ada hasil skrining.
                </p>
            )}

            <div className="mt-3 flex flex-wrap items-center gap-2">
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => void run()}
                    disabled={busy}
                >
                    {last ? 'Ulangi skrining' : 'Jalankan skrining TB'}
                </Button>
                <p className="text-muted-foreground text-[11px]">
                    Alat skrining AI — bukan pengganti diagnosis radiolog.
                </p>
            </div>
        </div>
    );
}
