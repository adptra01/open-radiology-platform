<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Jobs\RunAiInference;
use App\Models\AiResult;
use App\Models\Order;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\Study;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiInferenceTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'test-ai-key-1234';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\ProcedureSeeder::class);
        $this->seed(\Database\Seeders\ModalitySeeder::class);

        config(['dicom.api_key' => self::API_KEY]);
        $this->actingAs(\App\Models\User::factory()->create(['email' => 'ai@domain.test']));
    }

    private function withApiKey(): array
    {
        return ['X-API-Key' => self::API_KEY];
    }

    /** Buat file DICOM palsu di temp (agar AiClient bisa membaca). */
    private function makeFakeDicomFile(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ai') . '.dcm';
        file_put_contents($tmp, "fake-dicom-bytes");

        return $tmp;
    }

    private function makeOrder(OrderStatus $status = OrderStatus::Requested): Order
    {
        $patient = Patient::create(['name' => 'AI Person']);
        $procedure = Procedure::where('code', 'R-CHEST-1V')->firstOrFail();

        return Order::create([
            'patient_id' => $patient->id,
            'procedure_id' => $procedure->id,
            'status' => $status,
            'accession_number' => 'ACC-' . strtoupper(uniqid()),
        ]);
    }

    private function makeStudy(?Order $order = null, ?string $filePath = '__TEMP__'): Study
    {
        $resolvedPath = $filePath === '__TEMP__' ? $this->makeFakeDicomFile() : $filePath;

        return Study::create([
            'order_id' => $order?->id,
            'patient_id' => $order?->patient_id,
            'accession_number' => $order?->accession_number,
            'study_instance_uid' => '1.2.3.4.' . uniqid(),
            'series_instance_uid' => '1.2.3.5.' . uniqid(),
            'sop_instance_uid' => '1.2.3.6.' . uniqid(),
            'file_path' => $resolvedPath,
            'matched' => $order !== null,
        ]);
    }

    /**
     * Bentuk respons ai-worker yang NYATA (diverifikasi E2E 2026-09-17):
     * findings = [{name, probability}] + blok tb + focused. Worker sungguhan
     * selalu menyertakan 'tb' karena predict() memanggil predict_tb().
     */
    private function fakeWorkerOk(): void
    {
        Http::fake([
            '*/infer' => Http::response([
                'source' => '/data/chest-pa.dcm',
                'model' => 'densenet121-res224-all',
                'device' => 'cpu',
                'threshold' => 0.15,
                'findings' => [
                    ['name' => 'Lung Opacity', 'probability' => 0.692],
                    ['name' => 'Effusion', 'probability' => 0.6874],
                    ['name' => 'Cardiomegaly', 'probability' => 0.6869],
                ],
                'tb' => [
                    'available' => false,
                    'probability' => null,
                    'note' => 'TB weights not found — run the Colab fine-tune notebook',
                ],
                'focused' => [
                    'tuberculosis' => [],
                    'lung_disease' => ['Effusion' => 0.6874],
                ],
            ], 200),
        ]);
    }

    /**
     * Worker versi lama (tanpa blok 'tb' di /infer) tetapi bobot TB tersedia:
     * job harus jatuh ke endpoint /infer/tb sebagai fallback.
     */
    private function fakeLegacyWorkerWithTb(): void
    {
        Http::fake([
            '*/health' => Http::response([
                'status' => 'ok',
                'model' => 'densenet121-res224-all',
                'tb' => ['available' => true, 'weights' => '/weights/tb_densenet121.pt'],
            ], 200),
            '*/infer' => Http::response([
                'source' => '/data/chest-pa.dcm',
                'model' => 'densenet121-res224-all',
                'device' => 'cpu',
                'threshold' => 0.15,
                'findings' => [
                    ['name' => 'Lung Opacity', 'probability' => 0.692],
                    ['name' => 'Cardiomegaly', 'probability' => 0.6869],
                ],
                // sengaja TANPA 'tb' — meniru worker versi lama
            ], 200),
            '*/infer/tb' => Http::response([
                'available' => true,
                'probability' => 0.9821,
                'logit' => 4.01,
                'note' => 'alat skrining/triase — bukan alat diagnostik',
            ], 200),
        ]);
    }

    private function fakeWorkerError(): void
    {
        Http::fake([
            '*/infer' => Http::response(['detail' => 'Inference failed: torch error'], 422),
        ]);
    }

    public function test_job_posts_study_file_and_stores_completed_result(): void
    {
        $this->fakeWorkerOk();

        $order = $this->makeOrder();
        $study = $this->makeStudy($order);
        $aiResult = AiResult::create([
            'study_id' => $study->id,
            'order_id' => $order->id,
            'status' => 'PENDING',
        ]);

        (new RunAiInference($aiResult))->handle(app(\App\Services\AiClient::class));

        $fresh = $aiResult->fresh();
        $this->assertSame('COMPLETED', $fresh->status);
        $this->assertSame('Lung Opacity', $fresh->findings[0]['name']);
        $this->assertSame(0.692, $fresh->findings[0]['probability']);
        $this->assertCount(3, $fresh->findings);
        // Worker nyata sudah menyertakan blok tb di /infer → tersimpan apa adanya.
        $this->assertFalse($fresh->raw_report['tb']['available']);
        $this->assertNotNull($fresh->completed_at);
        $this->assertIsInt($fresh->inference_ms);
        $this->assertNull($fresh->error);

        // Hanya SATU panggilan (/infer) — tidak ada fallback /infer/tb.
        Http::assertSentCount(1);
        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/infer'));
    }

    public function test_job_marks_failed_when_worker_errors(): void
    {
        $this->fakeWorkerError();

        $study = $this->makeStudy();
        $aiResult = AiResult::create([
            'study_id' => $study->id,
            'status' => 'PENDING',
        ]);

        (new RunAiInference($aiResult))->handle(app(\App\Services\AiClient::class));

        $fresh = $aiResult->fresh();
        $this->assertSame('FAILED', $fresh->status);
        $this->assertStringContainsString('Inference failed', $fresh->error);
    }

    public function test_job_marks_failed_without_file_path(): void
    {
        $this->fakeWorkerOk();

        $study = $this->makeStudy(filePath: null);
        $aiResult = AiResult::create([
            'study_id' => $study->id,
            'status' => 'PENDING',
        ]);

        (new RunAiInference($aiResult))->handle(app(\App\Services\AiClient::class));

        $fresh = $aiResult->fresh();
        $this->assertSame('FAILED', $fresh->status);
        $this->assertStringContainsString('file_path', $fresh->error);
        Http::assertNothingSent();
    }

    /** Fallback: worker lama tanpa blok 'tb' → job memanggil /infer/tb terpisah. */
    public function test_job_falls_back_to_standalone_tb_endpoint_for_legacy_worker(): void
    {
        $this->fakeLegacyWorkerWithTb();

        $order = $this->makeOrder();
        $study = $this->makeStudy($order);
        $aiResult = AiResult::create([
            'study_id' => $study->id,
            'order_id' => $order->id,
            'status' => 'PENDING',
        ]);

        (new RunAiInference($aiResult))->handle(app(\App\Services\AiClient::class));

        $fresh = $aiResult->fresh();
        $this->assertSame('COMPLETED', $fresh->status);
        $this->assertTrue($fresh->raw_report['tb']['available'] ?? false);
        $this->assertEqualsWithDelta(0.9821, $fresh->raw_report['tb']['probability'] ?? 0, 0.001);
    }

    public function test_api_lists_and_shows_results(): void
    {
        $study = $this->makeStudy();
        $ai = AiResult::create([
            'study_id' => $study->id,
            'status' => 'COMPLETED',
            'findings' => [['name' => 'Cardiomegaly', 'score' => 0.9]],
        ]);

        $this->getJson('/api/ai-results')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson("/api/ai-results/{$ai->id}")
            ->assertOk()
            ->assertJsonPath('status', 'COMPLETED');
    }

    public function test_api_redispatch_runs_inference_for_study(): void
    {
        $this->fakeWorkerOk();

        $study = $this->makeStudy();

        $response = $this->postJson("/api/ai-results/run/{$study->id}")
            ->assertStatus(202);

        $this->assertDatabaseHas('ai_results', [
            'id' => $response->json('id'),
            'study_id' => $study->id,
            'status' => 'COMPLETED',
        ]);
    }

    public function test_study_store_creates_ai_result_record(): void
    {
        $order = $this->makeOrder();

        $response = $this->postJson('/api/dicom/studies', [
            'accession_number' => $order->accession_number,
            'study_instance_uid' => '1.2.3.4.5.6.7',
            'series_instance_uid' => '1.2.3.4.5.6.8',
            'sop_instance_uid' => '1.2.3.4.5.6.9',
            'sop_class_uid' => '1.2.840.10008.5.1.4.1.1.1',
            'modality' => 'CR',
            'study_description' => 'Chest PA',
        ], $this->withApiKey());

        $response->assertStatus(200);
        $this->assertNotNull($response->json('ai_result_id'));
        $this->assertDatabaseHas('ai_results', [
            'id' => $response->json('ai_result_id'),
            'study_id' => $response->json('study_id'),
            'status' => 'PENDING',
        ]);
    }
}