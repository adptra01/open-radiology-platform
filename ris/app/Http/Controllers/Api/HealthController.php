<?php

namespace App\Http\Controllers\Api;

use App\Services\PacsClient;
use App\Models\PacsSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Healthcheck endpoint untuk observability (kubernetes liveness/readiness,
-- monitoring, load balancer).
 *
 * GET /api/health  → 200 bila semua dependency sehat, 503 bila ada yang down.
 * Response JSON:
 *   {
 *     "status": "healthy|degraded|unhealthy",
 *     "checks": {
 *       "database": { "status": "ok|fail", "latency_ms": 3, "error": "..." },
 *       "queue":    { "status": "ok|fail", "pending_jobs": 12, "error": "..." },
 *       "ai_worker": { "status": "ok|fail", "latency_ms": 45, "error": "..." },
 *       "orthanc":  { "status": "ok|fail", "latency_ms": 12, "error": "..." }
 *     },
 *     "timestamp": "2026-09-17T..."
 *   }
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database'    => $this->checkDatabase(),
            'queue'       => $this->checkQueue(),
            'ai_worker'   => $this->checkAiWorker(),
            'orthanc'     => $this->checkOrthanc(),
        ];

        // DB + queue = kritikal; AI + Orthanc = non-kritikal (degraded bila gagal)
        $critical = collect($checks)->only(['database', 'queue'])->values()->all();
        $nonCritical = collect($checks)->only(['ai_worker', 'orthanc'])->values()->all();

        $criticalFail = collect($critical)->contains(fn ($c) => $c['status'] === 'fail');
        $nonCriticalFail = collect($nonCritical)->contains(fn ($c) => $c['status'] === 'fail');

        $overall = $criticalFail
            ? 'unhealthy'
            : ($nonCriticalFail ? 'degraded' : 'healthy');

        $statusCode = $overall === 'healthy' ? 200 : 503;

        return response()->json([
            'status'    => $overall,
            'checks'    => $checks,
            'timestamp' => now()->toIso8601String(),
        ], $statusCode);
    }

    private function checkDatabase(): array
    {
        $start = microtime(true);

        try {
            DB::connection()->getPdo()->query('SELECT 1');
            $latency = round((microtime(true) - $start) * 1000, 1);

            return [
                'status'      => 'ok',
                'latency_ms'  => $latency,
            ];
        } catch (Throwable $e) {
            return [
                'status'     => 'fail',
                'error'      => $e->getMessage(),
                'latency_ms' => round((microtime(true) - $start) * 1000, 1),
            ];
        }
    }

    private function checkQueue(): array
    {
        try {
            $pending = DB::table('jobs')->count();

            return [
                'status'        => 'ok',
                'pending_jobs'  => $pending,
            ];
        } catch (Throwable $e) {
            return [
                'status' => 'fail',
                'error'  => $e->getMessage(),
            ];
        }
    }

    private function checkAiWorker(): array
    {
        $url = config('ris.ai.url') ?? env('ORP_AI_URL', 'http://pydev:8000');
        $start = microtime(true);

        try {
            $res = Http::timeout(5)->acceptJson()->get($url . '/health');
            $latency = round((microtime(true) - $start) * 1000, 1);

            if ($res->successful()) {
                $data = $res->json();
                return [
                    'status'       => 'ok',
                    'latency_ms'   => $latency,
                    'tb_available' => $data['tb']['available'] ?? false,
                    'model'        => $data['model'] ?? null,
                ];
            }

            return [
                'status'   => 'fail',
                'error'    => 'HTTP ' . $res->status() . ': ' . substr($res->body(), 0, 100),
                'latency_ms' => $latency,
            ];
        } catch (Throwable $e) {
            return [
                'status'     => 'fail',
                'error'      => $e->getMessage(),
                'latency_ms' => round((microtime(true) - $start) * 1000, 1),
            ];
        }
    }

    private function checkOrthanc(): array
    {
        $url = config('ris.orthanc.base_url') ?? env('ORP_ORTHANC_URL', 'http://orthanc:8042');
        $username = config('ris.orthanc.username') ?? env('ORP_ORTHANC_USERNAME');
        $password = config('ris.orthanc.password') ?? env('ORP_ORTHANC_PASSWORD');
        $start = microtime(true);

        try {
            $req = Http::timeout(5)->acceptJson();
            if ($username && $password) {
                $req->withBasicAuth($username, $password);
            }
            $res = $req->get(rtrim($url, '/') . '/system');
            $latency = round((microtime(true) - $start) * 1000, 1);

            if ($res->successful()) {
                return [
                    'status'     => 'ok',
                    'latency_ms' => $latency,
                ];
            }

            return [
                'status'     => 'fail',
                'error'      => 'HTTP ' . $res->status() . ': ' . substr($res->body(), 0, 100),
                'latency_ms' => $latency,
            ];
        } catch (Throwable $e) {
            return [
                'status'     => 'fail',
                'error'      => $e->getMessage(),
                'latency_ms' => round((microtime(true) - $start) * 1000, 1),
            ];
        }
    }
}