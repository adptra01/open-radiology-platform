<?php

namespace Tests\Feature;

use App\Enums\OrderPriority;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Enums\TransmissionStatus;
use App\Jobs\ProcessTransmission;
use App\Models\Appointment;
use App\Models\Order;
use App\Models\PacsSource;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\Report;
use App\Models\Study;
use App\Models\Transmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * API alur kerja SPA (M7): order, appointment, study, transmisi, PACS.
 */
class WorkflowApiTest extends TestCase
{
    use RefreshDatabase;

    private User $registration;
    private User $scheduler;
    private User $radiographer;
    private User $pacsAdmin;
    private Patient $patient;
    private Procedure $procedure;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->seed(\Database\Seeders\ProcedureSeeder::class);
        $this->seed(\Database\Seeders\ModalitySeeder::class);

        $this->registration = $this->userWithRole('reg@domain.test', Role::Registration);
        $this->scheduler = $this->userWithRole('sched@domain.test', Role::Scheduler);
        $this->radiographer = $this->userWithRole('rad@domain.test', Role::Radiographer);
        $this->pacsAdmin = $this->userWithRole('pacs@domain.test', Role::PacsAdmin);

        $this->patient = Patient::create(['name' => 'Pasien Workflow']);
        $this->procedure = Procedure::where('code', 'R-CHEST-1V')->firstOrFail();
    }

    private function userWithRole(string $email, Role $role): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->assignRole($role->value);

        return $user;
    }

    private function makeOrder(array $attrs = []): Order
    {
        return Order::create(array_merge([
            'patient_id' => $this->patient->id,
            'procedure_id' => $this->procedure->id,
        ], $attrs));
    }

    private function makeStudy(Order $order, string $accession = 'ACC-TEST-0001'): Study
    {
        return Study::create([
            'order_id' => $order->id,
            'patient_id' => $order->patient_id,
            'accession_number' => $accession,
            'study_instance_uid' => '1.2.840.'.$order->id.'.1',
            'sop_instance_uid' => '1.2.840.'.$order->id.'.1.1',
            'sop_class_uid' => '1.2.840.10008.5.1.4.1.1.1',
            'modality' => 'CR',
            'study_description' => 'CHEST PA',
            'study_date' => now()->toDateString(),
            'matched' => true,
        ]);
    }

    public function test_order_crud_and_cancel_flow(): void
    {
        $created = $this->actingAs($this->registration)
            ->postJson('/api/orders', [
                'patient_id' => $this->patient->id,
                'procedure_id' => $this->procedure->id,
                'priority' => 'URGENT',
            ])
            ->assertCreated()
            ->assertJsonPath('status', OrderStatus::Requested->value)
            ->json();

        $this->assertNotEmpty($created['order_number']);
        $this->assertNotEmpty($created['accession_number']);

        $this->actingAs($this->registration)
            ->getJson('/api/orders?status=REQUESTED')
            ->assertOk()
            ->assertJsonPath('data.0.id', $created['id']);

        $this->actingAs($this->registration)
            ->putJson("/api/orders/{$created['id']}", ['priority' => OrderPriority::Stat->value])
            ->assertOk()
            ->assertJsonPath('priority', OrderPriority::Stat->value);

        $this->actingAs($this->registration)
            ->postJson("/api/orders/{$created['id']}/cancel", ['note' => 'Pasien batal'])
            ->assertOk()
            ->assertJsonPath('status', OrderStatus::Cancelled->value);

        // Order yang sudah CANCELLED tidak bisa dibatalkan lagi.
        $this->actingAs($this->registration)
            ->postJson("/api/orders/{$created['id']}/cancel")
            ->assertUnprocessable();
    }

    public function test_order_endpoints_enforce_permissions(): void
    {
        $auditor = $this->userWithRole('audit@domain.test', Role::Auditor);

        $this->actingAs($auditor)->getJson('/api/orders')->assertForbidden();

        // Radiografer boleh membaca worklist, tidak boleh membuat order.
        $this->actingAs($this->radiographer)->getJson('/api/orders')->assertOk();
        $this->actingAs($this->radiographer)
            ->postJson('/api/orders', ['patient_id' => $this->patient->id, 'procedure_id' => $this->procedure->id])
            ->assertForbidden();
    }

    public function test_order_detail_includes_studies_and_reports(): void
    {
        $order = $this->makeOrder();
        $study = $this->makeStudy($order);
        Report::create(['order_id' => $order->id, 'study_id' => $study->id]);

        $this->actingAs($this->radiographer)
            ->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonCount(1, 'studies')
            ->assertJsonCount(1, 'reports');
    }

    public function test_awaiting_report_excludes_orders_with_report(): void
    {
        $withReport = $this->makeOrder();
        $withReport->walkTo(OrderStatus::Completed);
        Report::create(['order_id' => $withReport->id]);

        $withoutReport = $this->makeOrder();
        $withoutReport->walkTo(OrderStatus::Completed);

        $response = $this->actingAs($this->radiographer)
            ->getJson('/api/orders/awaiting-report')
            ->assertOk();

        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($withoutReport->id, $ids);
        $this->assertNotContains($withReport->id, $ids);
    }

    public function test_appointment_lifecycle_and_transitions(): void
    {
        $order = $this->makeOrder();

        $appointment = $this->actingAs($this->scheduler)
            ->postJson('/api/appointments', [
                'order_id' => $order->id,
                'scheduled_at' => now()->addDay()->toDateTimeString(),
            ])
            ->assertCreated()
            ->json();

        $this->assertSame('SCHEDULED', $appointment['status']);

        foreach (['CONFIRMED', 'CHECKED_IN', 'COMPLETED'] as $target) {
            $this->actingAs($this->scheduler)
                ->postJson("/api/appointments/{$appointment['id']}/transition", ['target' => $target])
                ->assertOk()
                ->assertJsonPath('status', $target);
        }

        // Transisi tidak valid (COMPLETED → CANCELLED) ditolak.
        $this->actingAs($this->scheduler)
            ->postJson("/api/appointments/{$appointment['id']}/transition", ['target' => 'CANCELLED'])
            ->assertUnprocessable();

        // Target tak dikenal ditolak sebelum cek izin.
        $this->actingAs($this->scheduler)
            ->postJson("/api/appointments/{$appointment['id']}/transition", ['target' => 'NGACO'])
            ->assertUnprocessable();
    }

    public function test_appointment_requires_scheduling_permission(): void
    {
        $order = $this->makeOrder();

        $this->actingAs($this->radiographer)
            ->postJson('/api/appointments', [
                'order_id' => $order->id,
                'scheduled_at' => now()->addDay()->toDateTimeString(),
            ])
            ->assertForbidden();
    }

    public function test_appointment_reschedule_updates_slot(): void
    {
        $order = $this->makeOrder();
        $appointment = Appointment::create([
            'order_id' => $order->id,
            'scheduled_at' => now()->addDay(),
        ]);

        $newSlot = now()->addDays(5)->startOfHour();

        $this->actingAs($this->scheduler)
            ->putJson("/api/appointments/{$appointment->id}", ['scheduled_at' => $newSlot->toDateTimeString()])
            ->assertOk();

        $this->assertTrue($appointment->fresh()->scheduled_at->equalTo($newSlot));
        $this->assertDatabaseHas('audit_logs', ['action' => 'appointment.updated', 'auditable_id' => $appointment->id]);
    }

    public function test_study_list_and_detail(): void
    {
        $order = $this->makeOrder();
        $study = $this->makeStudy($order, 'ACC-TEST-0002');

        $this->actingAs($this->radiographer)
            ->getJson('/api/studies?search=ACC-TEST-0002')
            ->assertOk()
            ->assertJsonPath('data.0.accession_number', 'ACC-TEST-0002');

        // Catatan: relasi Eloquent diserialisasi snake_case (aiResults → ai_results).
        $this->actingAs($this->radiographer)
            ->getJson("/api/studies/{$study->id}")
            ->assertOk()
            ->assertJsonPath('study.study_instance_uid', $study->study_instance_uid)
            ->assertJsonStructure(['study' => ['order' => ['patient'], 'reports', 'ai_results', 'transmissions']]);

        $auditor = $this->userWithRole('audit2@domain.test', Role::Auditor);
        $this->actingAs($auditor)->getJson('/api/studies')->assertForbidden();
    }

    public function test_transmission_retry_resets_and_dispatches(): void
    {
        Queue::fake();

        $order = $this->makeOrder();
        $study = $this->makeStudy($order);
        $pacs = PacsSource::create([
            'name' => 'Orthanc Test',
            'ae_title' => 'ORTHANC',
            'base_url' => 'http://orthanc:8042/dicom-web',
            'is_active' => true,
        ]);

        $transmission = Transmission::create([
            'pacs_source_id' => $pacs->id,
            'study_id' => $study->id,
            'order_id' => $order->id,
            'transmission_type' => 'STOW',
            'status' => TransmissionStatus::Failed,
            'attempts' => 3,
            'error' => 'connection refused',
        ]);

        // Radiografer punya transmission.view, bukan transmission.retry.
        $this->actingAs($this->radiographer)
            ->getJson('/api/transmissions')
            ->assertOk();
        $this->actingAs($this->radiographer)
            ->postJson("/api/transmissions/{$transmission->id}/retry")
            ->assertForbidden();

        $this->actingAs($this->pacsAdmin)
            ->postJson("/api/transmissions/{$transmission->id}/retry")
            ->assertOk()
            ->assertJsonPath('status', TransmissionStatus::Pending->value);

        $fresh = $transmission->fresh();
        $this->assertSame(0, $fresh->attempts);
        $this->assertNull($fresh->error);

        Queue::assertPushed(ProcessTransmission::class);

        // Transmisi SENT tidak boleh di-retry.
        $fresh->update(['status' => TransmissionStatus::Sent]);
        $this->actingAs($this->pacsAdmin)
            ->postJson("/api/transmissions/{$transmission->id}/retry")
            ->assertUnprocessable();
    }

    public function test_pacs_crud_and_delete_guard(): void
    {
        $this->actingAs($this->pacsAdmin)
            ->postJson('/api/pacs', [
                'name' => 'Orthanc Baru',
                'ae_title' => 'ORTH2',
                'base_url' => 'http://orthanc:8042/dicom-web',
                'is_active' => true,
            ])
            ->assertCreated();

        $pacs = PacsSource::firstWhere('ae_title', 'ORTH2');

        Http::fake([
            'http://orthanc:8042/dicom-web/studies?limit=1' => Http::response('[]', 200),
        ]);

        $this->actingAs($this->pacsAdmin)
            ->getJson("/api/pacs/{$pacs->id}/ping")
            ->assertOk()
            ->assertJsonPath('reachable', true);

        // `index` hanya ping bila diminta (?ping=1) — tanpa itu harus null.
        $this->actingAs($this->pacsAdmin)
            ->getJson('/api/pacs')
            ->assertOk()
            ->assertJsonPath('data.0.reachable', null);

        $this->actingAs($this->pacsAdmin)
            ->putJson("/api/pacs/{$pacs->id}", ['name' => 'Orthanc Renamed'])
            ->assertOk()
            ->assertJsonPath('name', 'Orthanc Renamed');

        // Masih ada transmisi PENDING → tidak boleh dihapus.
        $order = $this->makeOrder();
        $study = $this->makeStudy($order);
        Transmission::create([
            'pacs_source_id' => $pacs->id,
            'study_id' => $study->id,
            'order_id' => $order->id,
            'transmission_type' => 'STOW',
            'status' => TransmissionStatus::Pending,
        ]);

        $this->actingAs($this->pacsAdmin)
            ->deleteJson("/api/pacs/{$pacs->id}")
            ->assertUnprocessable();

        Transmission::where('pacs_source_id', $pacs->id)->update(['status' => TransmissionStatus::Sent->value]);

        $this->actingAs($this->pacsAdmin)
            ->deleteJson("/api/pacs/{$pacs->id}")
            ->assertOk();

        $this->assertSoftDeleted('pacs_sources', ['id' => $pacs->id]);

        // Hanya PacsAdmin/Admin yang boleh menambah sumber PACS.
        $this->actingAs($this->registration)
            ->postJson('/api/pacs', ['name' => 'Nope', 'ae_title' => 'NOPE'])
            ->assertForbidden();
    }
}
