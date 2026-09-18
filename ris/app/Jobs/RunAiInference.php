<?php

namespace App\Jobs;

use App\Models\AiResult;
use App\Models\Study;
use App\Services\AiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Inferensi AI untuk study (M6).
 *
 * Alur: study diterima (C-STORE) → dispatch job → ambil file DICOM
 * → POST ke ai-worker /infer → simpan hasil ke ai_results.
 *
 * Non-fatal: bila ai-worker tidak aktif, status FAILED tercatat dan
 * job bisa di-retry manual (re-dispatch dari endpoint API).
 */
class RunAiInference implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 150;

    public function __construct(public AiResult $aiResult)
    {
        //
    }

    public function handle(AiClient $client): void
    {
        if ($this->aiResult->status === 'COMPLETED') {
            return; // sudah pernah selesai
        }

        $study = $this->aiResult->study()->with('order.patient')->first();
        if (! $study || blank($study->file_path)) {
            $this->aiResult->markFailed('Study tidak punya file_path (file fisik belum tersedia).');

            return;
        }

        $this->aiResult->markProcessing();

        $result = $client->infer($study->file_path);

        if ($result['ok'] && $result['report'] !== null) {
            $report = $result['report'];

            // M6 TB: skrining triase bila bobot fine-tune TB tersedia di worker.
            if (! isset($report['tb']) && $client->tbAvailable()) {
                $tb = $client->inferTb($study->file_path);
                if ($tb['ok'] && $tb['tb'] !== null) {
                    $report['tb'] = $tb['tb'];
                } else {
                    $report['tb'] = [
                        'available' => false,
                        'note' => 'pengecekan TB gagal: ' . ($tb['error'] ?? 'unknown'),
                    ];
                }
            }

            $this->aiResult->markCompleted($report, $result['elapsed_ms']);
            Log::info('AI inference completed', [
                'ai_result_id' => $this->aiResult->id,
                'study_id' => $study->id,
                'findings' => count($result['report']['findings'] ?? []),
                'elapsed_ms' => $result['elapsed_ms'],
            ]);
        } else {
            $this->aiResult->markFailed($result['error'] ?? 'inferensi gagal');
        }
    }
}