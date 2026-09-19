<?php

namespace App\Jobs;

use App\Models\AiResult;
use App\Models\AiRun;
use App\Services\AiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Eksekusi satu AI run generik (keputusan arsitektur final M10+).
 *
 * Alur: ai_runs(queued) → running → gateway POST /infer/{task}
 * → envelope completed → ai_runs(completed) + dual-write legacy
 * → envelope failed / transport error → ai_runs(failed + error_code).
 *
 * ai_runs = canonical; AiResult/raw_report['tb'] = legacy compatibility
 * (ditulis agar UI lama tetap hidup, bukan sumber utama).
 */
class RunAiRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 150;

    public function __construct(public AiRun $run)
    {
        //
    }

    public function handle(AiClient $client): void
    {
        $this->run->refresh();
        if ($this->run->isTerminal()) {
            return; // sudah selesai — idempotent
        }

        $study = $this->run->study()->with('order.patient')->first();
        if (! $study || blank($study->file_path)) {
            $this->run->markFailed('MISSING_INPUT', 'Study tidak punya file_path (file fisik belum tersedia).');

            return;
        }

        $this->run->markRunning();

        $result = $client->inferTask($this->run->task_id, $study->file_path);

        if (! $result['ok'] || ! is_array($result['envelope'])) {
            $this->run->markFailed('GATEWAY_UNREACHABLE', $result['error'] ?? 'AI Gateway tidak terjangkau.');
            Log::warning('AI run transport failed', ['run_id' => $this->run->run_id, 'error' => $result['error'] ?? null]);

            return;
        }

        $envelope = $result['envelope'];

        // Normalisasi payload worker legacy {available, probability, note}
        // (tanpa kunci 'status') menjadi envelope agar alur di bawah seragam.
        if (! isset($envelope['status'])) {
            $envelope = $this->normalizeLegacyPayload($envelope);
        }

        if (($envelope['status'] ?? null) === 'completed') {
            $this->run->markCompleted($envelope);
            $this->writeLegacyCompat($envelope);
            Log::info('AI run completed', [
                'run_id' => $this->run->run_id,
                'task' => $this->run->task_id,
                'elapsed_ms' => $result['elapsed_ms'],
            ]);

            return;
        }

        // AI NOT RUN (input tak valid, bobot hilang, dsb.) — failed + kode, bukan prediksi.
        $this->run->markFailed(
            $envelope['error']['code'] ?? 'INFERENCE_ERROR',
            $envelope['error']['message'] ?? 'AI tidak dapat memproses input ini.'
        );
    }

    /**
     * Normalisasi payload worker legacy {available, probability, note} menjadi
     * envelope. available=true → completed (label dari threshold 0.50
     * uncalibrated); available=false → failed dengan pesan note.
     */
    protected function normalizeLegacyPayload(array $payload): array
    {
        $task = ['id' => $this->run->task_id, 'name' => $this->run->task_id];
        $model = ['id' => $this->run->model_id, 'version' => $this->run->model_version];

        if (! empty($payload['available'])) {
            $score = (float) ($payload['probability'] ?? 0.0);

            return [
                'task' => $task,
                'model' => $model,
                'status' => 'completed',
                'result' => [
                    'type' => 'classification',
                    'classification' => ['label' => $score >= 0.5 ? 'positive' : 'negative', 'score' => $score, 'threshold' => 0.5],
                    'calibration' => ['status' => 'uncalibrated'],
                ],
                'metadata' => ['note' => $payload['note'] ?? null],
            ];
        }

        return [
            'task' => $task,
            'model' => $model,
            'status' => 'failed',
            'error' => ['code' => null, 'message' => $payload['note'] ?? 'AI tidak dapat memproses input ini.'],
            'metadata' => ['note' => $payload['note'] ?? null],
        ];
    }

    /**
     * Dual-write legacy: cerminkan hasil ke AiResult/raw_report agar UI lama
     * (AiTbCard membaca raw_report['tb']) tetap berfungsi. Bukan sumber utama.
     */
    protected function writeLegacyCompat(array $envelope): void
    {
        if ($this->run->task_id !== 'tb-screening') {
            return; // task lain tidak punya representasi legacy
        }

        $classification = $envelope['result']['classification'] ?? null;
        $tb = $classification ? [
            'available' => true,
            'probability' => $classification['score'] ?? null,
            'label' => $classification['label'] ?? null,
            'threshold' => $classification['threshold'] ?? null,
            'model' => $envelope['model'] ?? null,
            'note' => $envelope['metadata']['disclaimer'] ?? null,
        ] : [
            'available' => false,
            'probability' => null,
            'note' => 'Hasil task tidak berisi klasifikasi.',
        ];

        $legacy = AiResult::where('study_id', $this->run->study_id)->latest('id')->first();
        if ($legacy) {
            $raw = $legacy->raw_report ?? [];
            $raw['tb'] = $tb;
            $legacy->markCompleted($raw, 0);
        } else {
            AiResult::create([
                'study_id' => $this->run->study_id,
                'order_id' => $this->run->study?->order_id,
                'status' => 'COMPLETED',
                'model_name' => $envelope['model']['id'] ?? $this->run->task_id,
                'raw_report' => ['tb' => $tb],
                'completed_at' => now(),
            ]);
        }
    }
}
