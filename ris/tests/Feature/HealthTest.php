<?php

namespace Tests\Feature;

use App\Models\PacsSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_returns_healthy_when_all_deps_ok(): void
    {
        $aiUrl = config('ris.ai.url') . '/health';
        $orthancUrl = rtrim(config('ris.orthanc.base_url'), '/') . '/system';

        Http::fake([
            $aiUrl      => Http::response([
                'tb' => ['available' => false],
                'model' => 'densenet121-res224-all',
            ], 200),
            $orthancUrl => Http::response(['DicomAet' => 'ORTHANC'], 200),
        ]);

        $response = $this->getJson('/api/health');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'healthy')
            ->assertJsonStructure([
                'status',
                'checks' => [
                    'database' => ['status', 'latency_ms'],
                    'queue' => ['status', 'pending_jobs'],
                    'ai_worker' => ['status', 'latency_ms', 'tb_available'],
                    'orthanc' => ['status', 'latency_ms'],
                ],
                'timestamp',
            ]);
    }

    public function test_health_endpoint_returns_unhealthy_when_db_fails(): void
    {
        $aiUrl = config('ris.ai.url') . '/health';
        $orthancUrl = rtrim(config('ris.orthanc.base_url'), '/') . '/system';

        Http::fake([
            $aiUrl      => Http::response('', 503),
            $orthancUrl => Http::response(['DicomAet' => 'ORTHANC'], 200),
        ]);

        $response = $this->getJson('/api/health');

        $response->assertStatus(503)
            ->assertJsonPath('checks.ai_worker.status', 'fail');
    }

    public function test_health_endpoint_degraded_when_ai_down(): void
    {
        // Gunakan URL dari config agar Http::fake cocok (test env pakai host.docker.internal)
        $aiUrl = config('ris.ai.url') . '/health';
        $orthancUrl = rtrim(config('ris.orthanc.base_url'), '/') . '/system';

        Http::fake([
            $aiUrl       => Http::response('', 503),
            $orthancUrl  => Http::response('', 503),
        ]);

        $response = $this->getJson('/api/health');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.ai_worker.status', 'fail')
            ->assertJsonPath('checks.orthanc.status', 'fail');
    }
}