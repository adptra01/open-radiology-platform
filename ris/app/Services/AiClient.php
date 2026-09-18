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
     * Skrining TB (triase) — graceful bila worker tanpa bobot TB.
     * Return: ['ok' => true, 'tb' => array|['available'=>false,...]] + error/elapsed.
     */
    public function inferTb(string $filePath): array
    {
        if (! is_readable($filePath)) {
            return ['ok' => false, 'tb' => null, 'error' => 'file tidak terbaca', 'elapsed_ms' => 0];
        }

        $startMs = (int) (microtime(true) * 1000);

        try {
            $res = Http::timeout(120)->attach(
                'file',
                fopen($filePath, 'r'),
                basename((string) $filePath),
                ['Content-Type' => 'application/dicom'],
            )->post("{$this->baseUrl}/infer/tb");

            $elapsed = (int) (microtime(true) * 1000) - $startMs;

            if ($res->successful()) {
                return ['ok' => true, 'tb' => $res->json(), 'error' => null, 'elapsed_ms' => $elapsed];
            }

            return [
                'ok' => false,
                'tb' => null,
                'error' => substr((string) ($res->json()['detail'] ?? $res->body()), 0, 1000),
                'elapsed_ms' => $elapsed,
            ];
        } catch (Throwable $e) {
            $elapsed = (int) (microtime(true) * 1000) - $startMs;

            return ['ok' => false, 'tb' => null, 'error' => $e->getMessage(), 'elapsed_ms' => $elapsed];
        }
    }

    /** True bila ai-worker melaporkan model TB tersedia (bobot fine-tune ada). */
    public function tbAvailable(): bool
    {
        return ($this->ping()['data']['tb']['available'] ?? false) === true;
    }
}