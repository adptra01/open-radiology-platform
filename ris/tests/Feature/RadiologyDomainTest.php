<?php

namespace Tests\Feature;

use App\Enums\OrderPriority;
use App\Enums\OrderStatus;
use App\Models\Appointment;
use App\Models\Modality;
use App\Models\Order;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\User;
use App\Services\IdentifierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Enums\Role;
use Tests\TestCase;

class RadiologyDomainTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Procedure $chest;
    private Modality $cr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->seed(\Database\Seeders\ProcedureSeeder::class);
        $this->seed(\Database\Seeders\ModalitySeeder::class);
        $this->seed(\Database\Seeders\DoctorSeeder::class);

        $this->admin = User::factory()->create(['email' => 'admin@domain.test']);
        $this->admin->assignRole(Role::SuperAdmin->value);
        $this->actingAs($this->admin);

        $this->chest = Procedure::where('code', 'R-CHEST-1V')->firstOrFail();
        $this->cr = Modality::where('ae_title', 'ORPCR1')->firstOrFail();
    }

    public function test_patient_mrn_is_generated_automatically(): void
    {
        $patient = Patient::create(['name' => 'Test Person', 'gender' => 'male']);

        $this->assertMatchesRegularExpression('/^MRN-\d{8}-\d{4}$/', $patient->mrn);
        $this->assertDatabaseHas('patients', ['mrn' => $patient->mrn]);
    }

    public function test_order_gets_accession_and_defaults(): void
    {
        $patient = Patient::create(['name' => 'Acc Person']);
        $order = Order::create([
            'patient_id' => $patient->id,
            'procedure_id' => $this->chest->id,
        ]);

        $this->assertMatchesRegularExpression('/^ACC-\d{6}-\d{4}$/', $order->accession_number);
        // Regresi: AccessionNumber ber-VR DICOM SH → maks 16 karakter (dulu 17, ditolak modalitas).
        $this->assertLessThanOrEqual(
            IdentifierService::ACCESSION_MAX_LENGTH,
            strlen($order->accession_number),
            'Accession Number melebihi batas VR SH (16 karakter)'
        );
        $this->assertMatchesRegularExpression('/^ORD-\d{6}-\d{4}$/', $order->order_number);
        // Regresi: RequestedProcedureID MWL ber-VR DICOM SH → maks 16 karakter.
        $this->assertLessThanOrEqual(
            IdentifierService::ACCESSION_MAX_LENGTH,
            strlen($order->order_number),
            'Order Number melebihi batas VR SH (16 karakter)'
        );
        $this->assertTrue($order->status === OrderStatus::Requested);
        $this->assertTrue($order->priority === OrderPriority::Routine);
        $this->assertNotNull($order->requested_at);
    }

    public function test_explicit_mrn_and_accession_are_kept(): void
    {
        $patient = Patient::create(['name' => 'Custom', 'mrn' => 'MRN-20200101-0001']);
        $order = Order::create([
            'patient_id' => $patient->id,
            'procedure_id' => $this->chest->id,
            'accession_number' => 'ACC-20200101-0001',
        ]);

        $this->assertSame('MRN-20200101-0001', $patient->mrn);
        $this->assertSame('ACC-20200101-0001', $order->accession_number);
    }

    public function test_order_status_lifecycle_transitions(): void
    {
        $patient = Patient::create(['name' => 'Flow Person']);
        $order = Order::create([
            'patient_id' => $patient->id,
            'procedure_id' => $this->chest->id,
        ]);

        // Transisi legal
        $this->assertTrue($order->transitionTo(OrderStatus::Scheduled));
        $this->assertTrue($order->transitionTo(OrderStatus::Arrived));
        $this->assertTrue($order->transitionTo(OrderStatus::InProgress));
        $this->assertTrue($order->transitionTo(OrderStatus::Acquired));
        $this->assertTrue($order->transitionTo(OrderStatus::Completed));
        $this->assertNotNull($order->fresh()->completed_at);
        $this->assertTrue($order->fresh()->status === OrderStatus::Completed);

        // Status terminal: tidak boleh pindah lagi
        $this->assertFalse($order->transitionTo(OrderStatus::Cancelled));
    }

    public function test_illegal_transition_is_rejected(): void
    {
        $patient = Patient::create(['name' => 'Illegal Flow']);
        $order = Order::create([
            'patient_id' => $patient->id,
            'procedure_id' => $this->chest->id,
        ]);

        // Requested → langsung Completed tidak diizinkan
        $this->assertFalse($order->transitionTo(OrderStatus::Completed));
        $this->assertTrue($order->fresh()->status === OrderStatus::Requested);
    }

    public function test_accession_unique_at_database_level(): void
    {
        $patient = Patient::create(['name' => 'Dup Acc']);
        Order::create([
            'patient_id' => $patient->id,
            'procedure_id' => $this->chest->id,
            'accession_number' => 'ACC-20990101-1234',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Order::create([
            'patient_id' => $patient->id,
            'procedure_id' => $this->chest->id,
            'accession_number' => 'ACC-20990101-1234',
        ]);
    }

    public function test_appointment_chain_works(): void
    {
        $patient = Patient::create(['name' => 'Schedule Person']);
        $order = Order::create([
            'patient_id' => $patient->id,
            'procedure_id' => $this->chest->id,
            'modality_id' => $this->cr->id,
        ]);

        $apt = Appointment::create([
            'order_id' => $order->id,
            'modality_id' => $this->cr->id,
            'scheduled_at' => now()->addDay(),
        ]);

        $this->assertTrue($apt->order->is($order));
        $this->assertTrue($order->appointments()->count() === 1);
    }
}