<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * HTTP client ke ai-worker FastAPI (M6).
 *
 * Endpoints:
 *   GET  /health  → status model & TB availability
 *   POST /infer   → multipart file upload → structured findings
 *
 * Worker berjalan di CPU-only (TorchXRayVision DenseNet121).
 * Default URL: http://ai-worker:8000 (Docker network).
 * Konfigurasi: ORP_AI_URL env.
 */
class AiClient
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.ai_worker.url', env('ORP_AI_URL', 'http://127.0.0.1:8000')), '/');
    }

    /** Cek apakah ai-worker aktif dan model termuat. */
    public function ping(): array
    {
        try {
            $res = Http::timeout(5)->get("{$this->baseUrl}/health");

            if ($res->successful()) {
                return ['ok' => true, 'data' => $res->json()];
            }

            return ['ok' => false, 'error' => "HTTP {$res->status()}"];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Kirim file ke ai-worker untuk inferensi.
     * Return: ['ok' => bool, 'report' => array|null, 'error' => string|null, 'elapsed_ms' => int]
     */
    public function infer(string $filePath): array
    {
        if (! is_readable($filePath)) {
            return ['ok' => false, 'report' => null, 'error' => 'file tidak terbaca', 'elapsed_ms' => 0];
        }

        $startMs = (int) (microtime(true) * 1000);

        try {
            $res = Http::timeout(120)->attach(
                'file',
                fopen($filePath, 'r'),
                basename((string) $filePath),
                ['Content-Type' => 'application/dicom'],
            )->post("{$this->baseUrl}/infer");

            $elapsed = (int) (microtime(true) * 1000) - $startMs;

            if ($res->successful()) {
                $report = $res->json();
                $report['findings'] = $report['findings'] ?? [];

                return ['ok' => true, 'report' => $report, 'error' => null, 'elapsed_ms' => $elapsed];
            }

            $body = $res->json();
            $errorMsg = $body['detail'] ?? $res->body();

            return [
                'ok' => false,
                'report' => null,
                'error' => substr((string) $errorMsg, 0, 1000),
                'elapsed_ms' => $elapsed,
            ];
        } catch (Throwable $e) {
            $elapsed = (int) (microtime(true) * 1000) - $startMs;
            Log::warning('AiWorker infer failed', ['path' => $filePath, 'error' => $e->getMessage()]);

            return ['ok' => false, 'report' => null, 'error' => $e->getMessage(), 'elapsed_ms' => $elapsed];
        }
    }

    /**
     * Daftar kapabilitas task generik dari gateway (GET /capabilities).
     * Return: ['ok' => bool, 'tasks' => array, 'error' => string|null]
     * RIS merender tombol Run dari daftar ini — bukan hardcode nama task.
     */
    public function capabilities(): array
    {
        try {
            $res = Http::timeout(10)->get("{$this->baseUrl}/capabilities");

            if ($res->successful()) {
                return ['ok' => true, 'tasks' => $res->json('tasks', []), 'error' => null];
            }

            return ['ok' => false, 'tasks' => [], 'error' => "HTTP {$res->status()}"];
        } catch (Throwable $e) {
            return ['ok' => false, 'tasks' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Inferensi task generik (POST /infer/{task}).
     * Return: ['ok' => bool, 'envelope' => array|null, 'error' => string|null, 'elapsed_ms' => int]
     *
     * Catatan: gateway mengembalikan HTTP 200 + envelope status failed untuk
     * AI NOT RUN (input tak valid) — itu BUKAN transport error, payload
     * envelope-nya tetap diteruskan agar job mencatat error_code.
     */
    public function inferTask(string $taskId, string $filePath): array
    {
        if (! is_readable($filePath)) {
            return ['ok' => false, 'envelope' => null, 'error' => 'file tidak terbaca', 'elapsed_ms' => 0];
        }

        $result = $this->postInfer('infer/' . $taskId, $filePath);

        // Legacy fallback: worker lama hanya punya /infer/tb (tanpa /infer/{task}).
        // Dipicu bila endpoint generik 404 atau mengembalikan bodi kosong.
        if ($taskId === 'tb-screening' && ! $result['ok']) {
            $result = $this->postInfer('infer/tb', $filePath);
        }

        return $result;
    }

    /**
     * Satu percobaan POST file ke path gateway relatif.
     * Sukses HTTP + bodi JSON array → ok. Bodi kosong/bukan array → gagal
     * ('respons kosong') agar fallback legacy bisa dipicu dengan benar.
     */
    private function postInfer(string $relativePath, string $filePath): array
    {
        $startMs = (int) (microtime(true) * 1000);

        try {
            $res = Http::timeout(120)->attach(
                'file',
                fopen($filePath, 'r'),
                basename((string) $filePath),
                ['Content-Type' => 'application/dicom'],
            )->post("{$this->baseUrl}/" . ltrim($relativePath, '/'));

            $elapsed = (int) (microtime(true) * 1000) - $startMs;

            if ($res->successful()) {
                $body = $res->json();
                if (is_array($body)) {
                    return ['ok' => true, 'envelope' => $body, 'error' => null, 'elapsed_ms' => $elapsed];
                }

                return ['ok' => false, 'envelope' => null, 'error' => 'respons gateway kosong', 'elapsed_ms' => $elapsed];
            }

            return [
                'ok' => false,
                'envelope' => null,
                'error' => substr((string) ($res->json()['detail'] ?? $res->body()), 0, 1000),
                'elapsed_ms' => $elapsed,
            ];
        } catch (Throwable $e) {
            $elapsed = (int) (microtime(true) * 1000) - $startMs;

            return ['ok' => false, 'envelope' => null, 'error' => $e->getMessage(), 'elapsed_ms' => $elapsed];
        }
    }

    /**
     * Skrining TB (triase) — wrapper tipis di atas inferTask('tb-screening').
     * Bentuk return dipertahankan untuk back-compat (key 'tb' = envelope).
     * Return: ['ok' => true, 'tb' => array|['available'=>false,...]] + error/elapsed.
     */
    public function inferTb(string $filePath): array
    {
        $result = $this->inferTask('tb-screening', $filePath);

        // Normalisasi ke bentuk lama agar konsumen (job, card) tidak pecah:
        //  - envelope completed -> {available: true, probability, ...}
        //  - envelope failed    -> {available: false, reason_code/reason/note}
        //  - dict legacy worker -> diteruskan apa adanya (punya 'available').
        $envelope = $result['envelope'];
        if ($result['ok'] && is_array($envelope) && isset($envelope['status'])) {
            if ($envelope['status'] === 'failed') {
                $tb = [
                    'available' => false,
                    'probability' => null,
                    'reason_code' => $envelope['error']['code'] ?? null,
                    'reason' => $envelope['error']['message'] ?? null,
                    'note' => $envelope['metadata']['note'] ?? null,
                ];
            } else {
                $tb = [
                    'available' => true,
                    'probability' => $envelope['result']['classification']['score'] ?? null,
                    'label' => $envelope['result']['classification']['label'] ?? null,
                    'threshold' => $envelope['result']['classification']['threshold'] ?? null,
                    'model' => $envelope['model'] ?? null,
                    'note' => $envelope['metadata']['disclaimer'] ?? null,
                ];
            }

            return ['ok' => true, 'tb' => $tb, 'error' => null, 'elapsed_ms' => $result['elapsed_ms']];
        }

        return [
            'ok' => $result['ok'],
            'tb' => $envelope,
            'error' => $result['error'],
            'elapsed_ms' => $result['elapsed_ms'],
        ];
    }

    /** True bila ai-worker melaporkan model TB tersedia (bobot fine-tune ada). */
    public function tbAvailable(): bool
    {
        return ($this->ping()['data']['tb']['available'] ?? false) === true;
    }
}