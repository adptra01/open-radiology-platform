<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Jobs\RunAiRun;
use App\Models\AiResult;
use App\Models\AiRun;
use App\Models\Order;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\Study;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * AI Gateway generik: canonical ai_runs (keputusan arsitektur final M10+).
 *
 * Meliputi: POST /api/ai/runs (202 + queued), validasi task via capabilities,
 * GET show/index, job RunAiRun (completed + dual-write legacy, failed + kode),
 * dan format run_id AIR-YYMMDD-XXXX.
 */
class AiRunsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\ProcedureSeeder::class);
        $this->seed(\Database\Seeders\ModalitySeeder::class);

        $this->actingAs(\App\Models\User::factory()->create(['email' => 'airun@domain.test']));
    }

    private function makeFakeDicomFile(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'airun') . '.dcm';
        file_put_contents($tmp, 'fake-dicom-bytes');

        return $tmp;
    }

    private function makeOrder(OrderStatus $status = OrderStatus::Requested): Order
    {
        $patient = Patient::create(['name' => 'AI Run Person']);
        $procedure = Procedure::where('code', 'R-CHEST-1V')->firstOrFail();

        return Order::create([
            'patient_id' => $patient->id,
            'procedure_id' => $procedure->id,
            'status' => $status,
            'accession_number' => 'ACC-' . strtoupper(uniqid()),
        ]);
    }

    private function makeStudy(?Order $order = null): Study
    {
        return Study::create([
            'order_id' => $order?->id,
            'patient_id' => $order?->patient_id,
            'accession_number' => $order?->accession_number,
            'study_instance_uid' => '1.2.9.4.' . uniqid(),
            'series_instance_uid' => '1.2.9.5.' . uniqid(),
            'sop_instance_uid' => '1.2.9.6.' . uniqid(),
            'file_path' => $this->makeFakeDicomFile(),
            'matched' => $order !== null,
        ]);
    }

    private function fakeCapabilities(): void
    {
        Http::fake([
            '*/capabilities' => Http::response([
                'tasks' => [[
                    'task_id' => 'tb-screening',
                    'name' => 'TB Screening',
                    'modalities' => ['CR', 'DX'],
                    'body_regions' => ['CHEST'],
                    'input_type' => '2D_CXR',
                    'model' => ['id' => 'tb-densenet121', 'version' => '1.0'],
                    'available' => true,
                ]],
                'count' => 1,
            ], 200),
        ]);
    }

    private function completedEnvelope(): array
    {
        return [
            'task' => ['id' => 'tb-screening', 'name' => 'TB Screening'],
            'model' => ['id' => 'tb-densenet121', 'version' => '1.0'],
            'status' => 'completed',
            'input' => ['filename' => 'x.dcm'],
            'result' => [
                'type' => 'classification',
                'classification' => ['label' => 'positive', 'score' => 0.87, 'threshold' => 0.5],
                'calibration' => ['status' => 'uncalibrated'],
            ],
            'metadata' => ['processing_time_ms' => 842, 'disclaimer' => 'screening only'],
        ];
    }

    private function failedEnvelope(): array
    {
        return [
            'task' => ['id' => 'tb-screening', 'name' => 'TB Screening'],
            'model' => ['id' => 'tb-densenet121', 'version' => '1.0'],
            'status' => 'failed',
            'input' => ['filename' => 'ct.dcm'],
            'error' => ['code' => 'UNSUPPORTED_MODALITY', 'message' => 'Only chest X-ray.'],
            'metadata' => ['processing_time_ms' => 3, 'note' => "Modality 'CT' not allowed"],
        ];
    }

    public function test_capabilities_proxies_gateway(): void
    {
        $this->fakeCapabilities();

        $res = $this->getJson('/api/ai/capabilities');

        $res->assertOk();
        $res->assertJsonPath('data.0.task_id', 'tb-screening');
    }

    public function test_store_creates_queued_run_and_returns_202(): void
    {
        Queue::fake();
        $this->fakeCapabilities();
        $study = $this->makeStudy($this->makeOrder());

        $res = $this->postJson('/api/ai/runs', ['study_id' => $study->id, 'task_id' => 'tb-screening']);

        $res->assertStatus(202);
        $runId = $res->json('run_id');
        $this->assertMatchesRegularExpression('/^AIR-\d{6}-\d{4}$/', $runId);
        $this->assertSame(15, strlen($runId));
        $this->assertDatabaseHas('ai_runs', ['run_id' => $runId, 'status' => 'queued', 'task_id' => 'tb-screening']);
        Queue::assertPushed(RunAiRun::class);
    }

    public function test_store_rejects_unknown_task(): void
    {
        $this->fakeCapabilities();
        $study = $this->makeStudy($this->makeOrder());

        $res = $this->postJson('/api/ai/runs', ['study_id' => $study->id, 'task_id' => 'lung-nodule']);

        $res->assertStatus(422);
        $this->assertDatabaseCount('ai_runs', 0);
    }

    public function test_store_503_when_gateway_unreachable(): void
    {
        Http::fake(['*/capabilities' => Http::response(null, 500)]);
        $study = $this->makeStudy($this->makeOrder());

        $res = $this->postJson('/api/ai/runs', ['study_id' => $study->id, 'task_id' => 'tb-screening']);

        $res->assertStatus(503);
    }

    public function test_show_and_index(): void
    {
        Queue::fake();
        $this->fakeCapabilities();
        $study = $this->makeStudy($this->makeOrder());
        $runId = $this->postJson('/api/ai/runs', ['study_id' => $study->id, 'task_id' => 'tb-screening'])->json('run_id');

        $this->getJson("/api/ai/runs/{$runId}")->assertOk()->assertJsonPath('run_id', $runId);
        $this->getJson('/api/ai/runs/nonexistent')->assertNotFound();
        $this->getJson("/api/ai/runs?study_id={$study->id}")->assertOk();
    }

    public function test_job_completes_and_writes_legacy_compat(): void
    {
        $this->fakeCapabilities();
        Http::fake(['*/infer/tb-screening' => Http::response($this->completedEnvelope(), 200)]);

        $study = $this->makeStudy($this->makeOrder());
        $run = AiRun::create([
            'run_id' => app(\App\Services\IdentifierService::class)->nextRunId(),
            'study_id' => $study->id,
            'task_id' => 'tb-screening',
            'status' => AiRun::STATUS_QUEUED,
        ]);

        (new RunAiRun($run))->handle(app(\App\Services\AiClient::class));

        $run->refresh();
        $this->assertSame(AiRun::STATUS_COMPLETED, $run->status);
        $this->assertSame('positive', $run->result['classification']['label']);
        $this->assertSame('tb-densenet121', $run->model_id);

        // Legacy compatibility: AiResult/raw_report['tb'] tercermin.
        $legacy = AiResult::where('study_id', $study->id)->latest('id')->first();
        $this->assertNotNull($legacy);
        $this->assertTrue($legacy->raw_report['tb']['available']);
        $this->assertSame(0.87, $legacy->raw_report['tb']['probability']);
    }

    public function test_job_failed_envelope_records_error_code(): void
    {
        $this->fakeCapabilities();
        Http::fake(['*/infer/tb-screening' => Http::response($this->failedEnvelope(), 200)]);

        $study = $this->makeStudy($this->makeOrder());
        $run = AiRun::create([
            'run_id' => app(\App\Services\IdentifierService::class)->nextRunId(),
            'study_id' => $study->id,
            'task_id' => 'tb-screening',
            'status' => AiRun::STATUS_QUEUED,
        ]);

        (new RunAiRun($run))->handle(app(\App\Services\AiClient::class));

        $run->refresh();
        $this->assertSame(AiRun::STATUS_FAILED, $run->status);
        $this->assertSame('UNSUPPORTED_MODALITY', $run->error_code);
        // AI NOT RUN: tidak ada prediksi palsu yang tersimpan.
        $this->assertNull($run->result);
    }

    public function test_job_skips_terminal_runs(): void
    {
        $study = $this->makeStudy($this->makeOrder());
        $run = AiRun::create([
            'run_id' => app(\App\Services\IdentifierService::class)->nextRunId(),
            'study_id' => $study->id,
            'task_id' => 'tb-screening',
            'status' => AiRun::STATUS_COMPLETED,
        ]);

        Http::fake([]); // transport apa pun tidak boleh dipakai
        (new RunAiRun($run))->handle(app(\App\Services\AiClient::class));

        $this->assertSame(AiRun::STATUS_COMPLETED, $run->refresh()->status);
    }

    public function test_store_writes_created_audit_with_run_correlation(): void
    {
        Queue::fake();
        $this->fakeCapabilities();
        $study = $this->makeStudy($this->makeOrder());

        $runId = $this->postJson('/api/ai/runs', ['study_id' => $study->id, 'task_id' => 'tb-screening'])->json('run_id');

        $audit = \App\Models\AuditLog::where('action', 'ai_run.created')->firstOrFail();
        $this->assertSame($runId, $audit->changes['run_id']);
        $this->assertSame('tb-screening', $audit->changes['task_id']);
        $this->assertSame('App\\Models\\AiRun', $audit->auditable_type);
        // Tanpa pixel data / isi DICOM di audit log.
        $dump = json_encode([$audit->changes, $audit->auditable_type]);
        $this->assertStringNotContainsStringIgnoringCase('pixeldata', $dump);
        $this->assertStringNotContainsString('file_path', $dump);
    }

    public function test_job_writes_lifecycle_audits(): void
    {
        $this->fakeCapabilities();
        Http::fake(['*/infer/tb-screening' => Http::response($this->completedEnvelope(), 200)]);

        $study = $this->makeStudy($this->makeOrder());
        $run = AiRun::create([
            'run_id' => app(\App\Services\IdentifierService::class)->nextRunId(),
            'study_id' => $study->id,
            'task_id' => 'tb-screening',
            'status' => AiRun::STATUS_QUEUED,
        ]);

        (new RunAiRun($run))->handle(app(\App\Services\AiClient::class));

        $actions = \App\Models\AuditLog::where('auditable_type', 'App\\Models\\AiRun')
            ->where('auditable_id', $run->id)->pluck('action')->all();
        $this->assertContains('ai_run.started', $actions);
        $this->assertContains('ai_run.completed', $actions);
    }

    public function test_job_failed_audit_carries_error_code(): void
    {
        $this->fakeCapabilities();
        Http::fake(['*/infer/tb-screening' => Http::response($this->failedEnvelope(), 200)]);

        $study = $this->makeStudy($this->makeOrder());
        $run = AiRun::create([
            'run_id' => app(\App\Services\IdentifierService::class)->nextRunId(),
            'study_id' => $study->id,
            'task_id' => 'tb-screening',
            'status' => AiRun::STATUS_QUEUED,
        ]);

        (new RunAiRun($run))->handle(app(\App\Services\AiClient::class));

        $audit = \App\Models\AuditLog::where('action', 'ai_run.failed')
            ->where('auditable_id', $run->id)->firstOrFail();
        $this->assertSame('UNSUPPORTED_MODALITY', $audit->changes['error_code']);
    }

    public function test_show_writes_viewed_audit(): void
    {
        $run = AiRun::create([
            'run_id' => app(\App\Services\IdentifierService::class)->nextRunId(),
            'study_id' => $this->makeStudy($this->makeOrder())->id,
            'task_id' => 'tb-screening',
            'status' => AiRun::STATUS_COMPLETED,
        ]);

        $this->getJson("/api/ai/runs/{$run->run_id}")->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ai_run.viewed',
            'auditable_id' => $run->id,
        ]);
    }
}
