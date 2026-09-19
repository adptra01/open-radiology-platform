<?php

namespace Tests\Feature;

use App\Enums\OrderPriority;
use App\Enums\OrderStatus;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Modality;
use App\Models\MppsRecord;
use App\Models\Order;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\Study;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DicomApiTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'test-dicom-key-1234';

    private Patient $patient;
    private Procedure $chest;
    private Modality $cr;

    protected function setUp(): void
    {
        parent::setUp();

        config(['dicom.api_key' => self::API_KEY]);

        $this->seed(\Database\Seeders\ProcedureSeeder::class);
        $this->seed(\Database\Seeders\ModalitySeeder::class);

        $this->chest = Procedure::where('code', 'R-CHEST-1V')->firstOrFail();
        $this->cr = Modality::where('ae_title', 'ORPCR1')->firstOrFail();
        $this->patient = Patient::create(['name' => 'Worklist Patient', 'gender' => 'male', 'birth_date' => '1990-05-05']);
    }

    private function makeOrder(OrderStatus $status = OrderStatus::Requested): Order
    {
        return Order::create([
            'patient_id' => $this->patient->id,
            'procedure_id' => $this->chest->id,
            'modality_id' => $this->cr->id,
            'priority' => OrderPriority::Routine,
            'status' => $status,
            'scheduled_at' => now()->addDay(),
        ]);
    }

    private function withApiKey(): array
    {
        return ['X-API-Key' => self::API_KEY];
    }

    public function test_worklist_requires_valid_api_key(): void
    {
        $this->getJson('/api/dicom/worklists')->assertUnauthorized();
        $this->getJson('/api/dicom/worklists', ['X-API-Key' => 'wrong'])->assertUnauthorized();
        $this->getJson('/api/dicom/worklists', $this->withApiKey())->assertOk();
    }

    public function test_worklist_returns_only_active_orders(): void
    {
        $this->makeOrder(OrderStatus::Requested);
        $this->makeOrder(OrderStatus::Scheduled);
        $completed = $this->makeOrder(OrderStatus::Completed);

        $response = $this->getJson('/api/dicom/worklists', $this->withApiKey())->assertOk();

        $this->assertCount(2, $response->json('items'));
        foreach ($response->json('items') as $item) {
            $this->assertNotSame($completed->accession_number, $item['accession_number']);
        }
    }

    public function test_worklist_item_has_dicom_ready_fields(): void
    {
        $order = $this->makeOrder(OrderStatus::Scheduled);

        $item = $this->getJson('/api/dicom/worklists', $this->withApiKey())
            ->assertOk()
            ->json('items.0');

        $this->assertSame($order->accession_number, $item['accession_number']);
        $this->assertSame($this->patient->mrn, $item['patient_id']);
        $this->assertSame('Worklist Patient', $item['patient_name']);
        $this->assertSame('M', $item['patient_sex']);
        $this->assertSame('19900505', $item['patient_birth_date']);
        $this->assertSame($order->order_number, $item['requested_procedure_id']);
        $this->assertSame('Chest X-Ray 1 View', $item['requested_procedure_description']);
        $this->assertSame('ORPCR1', $item['scheduled_station_aetitle']);
        $this->assertSame('CR', $item['modality']);
        $this->assertMatchesRegularExpression('/^\d{8}$/', $item['scheduled_start_date']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $item['scheduled_start_time']);
    }

    public function test_worklist_filters_by_accession_and_modality(): void
    {
        $orderA = $this->makeOrder();
        $orderB = $this->makeOrder();
        $orderB->update(['procedure_id' => Procedure::where('code', 'CT-HEAD-PLAIN')->firstOrFail()->id]);

        $byAccession = $this->getJson("/api/dicom/worklists?AccessionNumber={$orderA->accession_number}", $this->withApiKey())->assertOk();
        $this->assertCount(1, $byAccession->json('items'));
        $this->assertSame($orderA->accession_number, $byAccession->json('items.0.accession_number'));

        $byModality = $this->getJson('/api/dicom/worklists?Modality=CT', $this->withApiKey())->assertOk();
        $this->assertCount(1, $byModality->json('items'));
        $this->assertSame($orderB->accession_number, $byModality->json('items.0.accession_number'));
    }

    public function test_mpps_n_create_advances_order_to_in_progress(): void
    {
        $order = $this->makeOrder(OrderStatus::Scheduled);

        $response = $this->postJson('/api/dicom/mpps', [
            'type' => 'N-CREATE',
            'AccessionNumber' => $order->accession_number,
            'PerformedProcedureStepStatus' => 'IN PROGRESS',
        ], $this->withApiKey())->assertOk();

        $this->assertTrue($response->json('matched'));
        $this->assertSame(OrderStatus::InProgress->value, $response->json('status'));
        $this->assertSame(OrderStatus::InProgress->value, $order->fresh()->status->value);
    }

    public function test_mpps_n_set_completed_advances_to_acquired(): void
    {
        $order = $this->makeOrder(OrderStatus::InProgress);

        $response = $this->postJson('/api/dicom/mpps', [
            'type' => 'N-SET',
            'AccessionNumber' => $order->accession_number,
            'PerformedProcedureStepStatus' => 'COMPLETED',
        ], $this->withApiKey())->assertOk();

        $this->assertSame(OrderStatus::Acquired->value, $response->json('status'));
    }

    public function test_mpps_with_unknown_accession_returns_unmatched(): void
    {
        $response = $this->postJson('/api/dicom/mpps', [
            'type' => 'N-CREATE',
            'AccessionNumber' => 'ACC-999999-9999',
            'PerformedProcedureStepStatus' => 'IN PROGRESS',
        ], $this->withApiKey())->assertOk();

        $this->assertFalse($response->json('matched'));
    }

    public function test_mpps_n_create_records_sop_uid_mapping(): void
    {
        $order = $this->makeOrder(OrderStatus::Scheduled);

        $this->postJson('/api/dicom/mpps', [
            'type' => 'N-CREATE',
            'SOPInstanceUID' => '1.2.840.999.1',
            'AccessionNumber' => $order->accession_number,
            'Modality' => 'CR',
            'PerformedProcedureStepStatus' => 'IN PROGRESS',
        ], $this->withApiKey())->assertOk()->assertJson([
            'matched' => true,
            'sop_instance_uid' => '1.2.840.999.1',
        ]);

        $this->assertDatabaseHas('mpps_records', [
            'sop_instance_uid' => '1.2.840.999.1',
            'accession_number' => $order->accession_number,
            'order_id' => $order->id,
            'status' => 'IN PROGRESS',
            'modality' => 'CR',
        ]);
    }

    public function test_mpps_n_set_without_accession_resolves_from_sop_record(): void
    {
        $order = $this->makeOrder(OrderStatus::Scheduled);

        // N-CREATE mendaftarkan peta SOPInstanceUID → accession.
        $this->postJson('/api/dicom/mpps', [
            'type' => 'N-CREATE',
            'SOPInstanceUID' => '1.2.840.999.2',
            'AccessionNumber' => $order->accession_number,
            'PerformedProcedureStepStatus' => 'IN PROGRESS',
        ], $this->withApiKey())->assertOk()->assertJson(['matched' => true]);

        // N-SET **tanpa** AccessionNumber — situasi setelah adapter/worker restart.
        $response = $this->postJson('/api/dicom/mpps', [
            'type' => 'N-SET',
            'SOPInstanceUID' => '1.2.840.999.2',
            'PerformedProcedureStepStatus' => 'COMPLETED',
        ], $this->withApiKey())->assertOk();

        $this->assertTrue($response->json('matched'));
        $this->assertSame(OrderStatus::Acquired->value, $response->json('status'));
        $this->assertSame($order->fresh()->status, OrderStatus::Acquired);

        $this->assertDatabaseHas('mpps_records', [
            'sop_instance_uid' => '1.2.840.999.2',
            'status' => 'COMPLETED',
        ]);

        // Diresolusi dari tabel, bukan dari payload.
        $audit = AuditLog::where('action', 'dicom.mpps')->latest('id')->firstOrFail();
        $this->assertSame('mpps_record', $audit->changes['accession_source']);
        $this->assertSame('1.2.840.999.2', $audit->changes['sop_instance_uid']);
    }

    public function test_mpps_with_sop_only_and_no_record_is_unresolved(): void
    {
        $this->postJson('/api/dicom/mpps', [
            'type' => 'N-SET',
            'SOPInstanceUID' => '1.2.840.999.3',
            'PerformedProcedureStepStatus' => 'COMPLETED',
        ], $this->withApiKey())->assertOk()->assertJson([
            'matched' => false,
            'reason' => 'accession unresolved',
        ]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'dicom.mpps.unresolved']);
        // Tidak ada yang bisa diingat → jangan buat baris sampah.
        $this->assertDatabaseMissing('mpps_records', ['sop_instance_uid' => '1.2.840.999.3']);
    }

    public function test_mpps_n_create_twice_for_same_sop_keeps_single_record(): void
    {
        $order = $this->makeOrder(OrderStatus::Scheduled);
        $payload = [
            'type' => 'N-CREATE',
            'SOPInstanceUID' => '1.2.840.999.4',
            'AccessionNumber' => $order->accession_number,
            'PerformedProcedureStepStatus' => 'IN PROGRESS',
        ];

        $this->postJson('/api/dicom/mpps', $payload, $this->withApiKey())->assertOk();
        $this->postJson('/api/dicom/mpps', $payload, $this->withApiKey())->assertOk();

        $this->assertSame(1, MppsRecord::where('sop_instance_uid', '1.2.840.999.4')->count());
    }

    public function test_mpps_n_action_without_accession_cancels_order_via_record(): void
    {
        $order = $this->makeOrder(OrderStatus::InProgress);

        $this->postJson('/api/dicom/mpps', [
            'type' => 'N-CREATE',
            'SOPInstanceUID' => '1.2.840.999.5',
            'AccessionNumber' => $order->accession_number,
            'PerformedProcedureStepStatus' => 'IN PROGRESS',
        ], $this->withApiKey())->assertOk();

        $response = $this->postJson('/api/dicom/mpps', [
            'type' => 'N-ACTION',
            'SOPInstanceUID' => '1.2.840.999.5',
            'PerformedProcedureStepStatus' => 'DISCONTINUED',
        ], $this->withApiKey())->assertOk();

        $this->assertTrue($response->json('matched'));
        $this->assertSame(OrderStatus::Cancelled->value, $response->json('status'));

        $record = MppsRecord::where('sop_instance_uid', '1.2.840.999.5')->firstOrFail();
        $this->assertNotNull($record->performed_ended_at);
    }

    public function test_study_forward_matches_order_and_completes_it(): void
    {
        $order = $this->makeOrder(OrderStatus::Acquired);

        $response = $this->postJson('/api/dicom/studies', [
            'accession_number' => $order->accession_number,
            'patient_id' => $this->patient->mrn,
            'patient_name' => 'Worklist Patient',
            'study_instance_uid' => '1.2.3.4.5.6.7',
            'series_instance_uid' => '1.2.3.4.5.6.8',
            'sop_instance_uid' => '1.2.3.4.5.6.9',
            'sop_class_uid' => '1.2.840.10008.5.1.4.1.1.1',
            'modality' => 'CR',
            'study_description' => 'Chest PA',
        ], $this->withApiKey())->assertOk();

        $this->assertTrue($response->json('matched'));
        $this->assertSame(OrderStatus::Completed->value, $response->json('order_status'));

        $this->assertDatabaseHas('studies', [
            'sop_instance_uid' => '1.2.3.4.5.6.9',
            'order_id' => $order->id,
            'matched' => true,
        ]);
        $this->assertNotNull($order->fresh()->completed_at);
    }

    public function test_study_forward_unmatched_is_stored_instead(): void
    {
        $response = $this->postJson('/api/dicom/studies', [
            'accession_number' => 'ACC-00000000-0000',
            'study_instance_uid' => '9.9.9.9',
            'series_instance_uid' => '9.9.9.8',
            'sop_instance_uid' => '9.9.9.7',
            'modality' => 'CT',
        ], $this->withApiKey())->assertStatus(201);

        $this->assertFalse($response->json('matched'));
        $this->assertDatabaseHas('studies', [
            'sop_instance_uid' => '9.9.9.7',
            'accession_number' => 'ACC-00000000-0000',
            'matched' => false,
        ]);
    }

    public function test_study_forward_requires_sop_instance_uid(): void
    {
        $this->postJson('/api/dicom/studies', ['accession_number' => 'ACC-1'], $this->withApiKey())
            ->assertStatus(422);
    }

    public function test_study_forward_resolves_storage_key_via_inbox_disk(): void
    {
        // M12.2 B5: storage_key relatif di-resolve via ORP_INBOX_PATH bila
        // file_path absolut tak terbaca lintas-container.
        $inbox = sys_get_temp_dir() . '/inbox-' . uniqid();
        mkdir($inbox);
        file_put_contents($inbox . '/study1.dcm', 'fake-dicom-bytes');
        config(['services.dicom.inbox_path' => $inbox]);

        $this->postJson('/api/dicom/studies', [
            'accession_number' => 'ACC-00000000-0000',
            'study_instance_uid' => '9.9.8.9',
            'series_instance_uid' => '9.9.8.8',
            'sop_instance_uid' => '9.9.8.7',
            'modality' => 'CR',
            'file_path' => '/nonexistent/container/path/study1.dcm',
            'storage_key' => 'study1.dcm',
        ], $this->withApiKey())->assertStatus(201);

        $this->assertDatabaseHas('studies', [
            'sop_instance_uid' => '9.9.8.7',
            'file_path' => $inbox . '/study1.dcm',
        ]);
    }

    public function test_adapter_announce_online_creates_audit(): void
    {
        $this->postJson('/api/dicom/adapters/online', [
            'ae_title' => 'ORP_RIS',
            'mwl_port' => 4243,
            'mpps_port' => 4244,
            'store_port' => 4245,
        ], $this->withApiKey())->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'dicom.adapter.online']);
    }

    public function test_full_mwl_to_completed_flow(): void
    {
        $order = $this->makeOrder(OrderStatus::Requested);

        // 1. MWL: order terlihat
        $this->getJson('/api/dicom/worklists', $this->withApiKey())
            ->assertOk()
            ->assertJsonCount(1, 'items');

        // 2. MPPS N-CREATE → IN_PROGRESS (auto walk dari REQUESTED)
        $this->postJson('/api/dicom/mpps', [
            'type' => 'N-CREATE',
            'AccessionNumber' => $order->accession_number,
            'PerformedProcedureStepStatus' => 'IN PROGRESS',
        ], $this->withApiKey())->assertJson(['status' => OrderStatus::InProgress->value]);

        // 3. MPPS N-SET COMPLETED → ACQUIRED
        $this->postJson('/api/dicom/mpps', [
            'type' => 'N-SET',
            'AccessionNumber' => $order->accession_number,
            'PerformedProcedureStepStatus' => 'COMPLETED',
        ], $this->withApiKey())->assertJson(['status' => OrderStatus::Acquired->value]);

        // 4. C-STORE → COMPLETED
        $this->postJson('/api/dicom/studies', [
            'accession_number' => $order->accession_number,
            'study_instance_uid' => '1.1.1.1',
            'series_instance_uid' => '1.1.1.2',
            'sop_instance_uid' => '1.1.1.3',
            'modality' => 'CR',
        ], $this->withApiKey())->assertJson(['order_status' => OrderStatus::Completed->value]);

        // 5. Order selesai tidak boleh muncul di MWL lagi
        $this->getJson('/api/dicom/worklists', $this->withApiKey())->assertJsonCount(0, 'items');
        $this->assertDatabaseHas('studies', ['order_id' => $order->id, 'matched' => true]);
    }
}