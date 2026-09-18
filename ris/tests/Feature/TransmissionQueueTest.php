<?php

namespace Tests\Feature;

use App\Enums\TransmissionStatus;
use App\Jobs\ProcessTransmission;
use App\Models\PacsSource;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\Study;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TransmissionQueueTest extends TestCase
{
    use RefreshDatabase;

    private PacsSource $pacs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\ProcedureSeeder::class);
        $this->seed(\Database\Seeders\ModalitySeeder::class);

        $this->pacs = PacsSource::create([
            'name' => 'Orthanc Test',
            'ae_title' => 'ORTHANC',
            'stow_url' => 'http://orthanc:8042/dicom-web/studies',
            'is_active' => true,
        ]);
    }

    private function makeStudy(): Study
    {
        $patient = Patient::create(['name' => 'Tx Person']);
        $procedure = Procedure::where('code', 'R-CHEST-1V')->firstOrFail();
        $order = \App\Models\Order::create(['patient_id' => $patient->id, 'procedure_id' => $procedure->id]);
        $tmp = tempnam(sys_get_temp_dir(), 'stow') . '.dcm';
        file_put_contents($tmp, "fake-dicom-bytes");

        return Study::create([
            'order_id' => $order->id,
            'patient_id' => $patient->id,
            'accession_number' => $order->accession_number,
            'study_instance_uid' => '1.2.3.4.' . uniqid(),
            'series_instance_uid' => '1.2.3.5.' . uniqid(),
            'sop_instance_uid' => '1.2.3.6.' . uniqid(),
            'file_path' => $tmp,
            'matched' => true,
        ]);
    }

    public function test_study_queue_transmission_dispatches_job(): void
    {
        Queue::fake();
        $study = $this->makeStudy();

        $study->queueTransmission();

        Queue::assertPushed(ProcessTransmission::class);
        $this->assertDatabaseHas('transmissions', [
            'study_id' => $study->id,
            'status' => TransmissionStatus::Pending->value,
            'transmission_type' => 'STOW',
            'pacs_source_id' => $this->pacs->id,
        ]);
    }

    public function test_transmission_marks_sent_on_success(): void
    {
        Http::fake([
            'http://orthanc:8042/dicom-web/studies' => Http::response('ok', 200),
        ]);

        $study = $this->makeStudy();
        $transmission = $study->queueTransmission();
        $this->assertNotNull($transmission);

        (new ProcessTransmission($transmission->fresh()))->handle();

        $this->assertSame(TransmissionStatus::Sent, $transmission->fresh()->status);
        $this->assertNotNull($transmission->fresh()->sent_at);
        $this->assertNotNull($transmission->fresh()->completed_at);
    }

    public function test_transmission_retries_then_fails(): void
    {
        Http::fake([
            'http://orthanc:8042/dicom-web/studies' => Http::response('server error', 500),
        ]);

        $study = $this->makeStudy();
        $transmission = $study->queueTransmission();
        $this->assertNotNull($transmission);

        $t = $transmission->fresh();
        for ($i = 0; $i < $t->max_attempts + 2; $i++) {
            $t = $t->fresh();
            if ($t->status->value === TransmissionStatus::Failed->value) {
                break;
            }
            (new ProcessTransmission($t))->handle();
        }

        $this->assertSame(TransmissionStatus::Failed, $t->fresh()->status);
        $this->assertGreaterThan(0, $t->fresh()->attempts);
        $this->assertNotNull($t->fresh()->error);
    }

    public function test_study_without_file_skips_transmission(): void
    {
        Queue::fake();
        $patient = Patient::create(['name' => 'NoFile']);
        $procedure = Procedure::where('code', 'R-CHEST-1V')->firstOrFail();
        $order = \App\Models\Order::create(['patient_id' => $patient->id, 'procedure_id' => $procedure->id]);

        $study = Study::create([
            'order_id' => $order->id,
            'patient_id' => $patient->id,
            'study_instance_uid' => '1.2.3.4.' . uniqid(),
            'series_instance_uid' => '1.2.3.5.' . uniqid(),
            'sop_instance_uid' => '1.2.3.6.' . uniqid(),
        ]);

        $this->assertNull($study->queueTransmission());
        Queue::assertNothingPushed();
    }
}