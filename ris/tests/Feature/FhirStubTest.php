<?php

namespace Tests\Feature;

use App\Enums\ReportStatus;
use App\Enums\Role;
use App\Models\Order;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\Report;
use App\Models\Study;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FhirStubTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->seed(\Database\Seeders\ProcedureSeeder::class);
        $this->seed(\Database\Seeders\ModalitySeeder::class);

        $this->user = User::factory()->create();
        $this->user->assignRole(Role::SuperAdmin->value);
        // FHIR dilindungi token Bearer Sanctum (bukan sesi web) — lihat routes/api.php.
        Sanctum::actingAs($this->user, ['fhir:read']);
    }

    public function test_fhir_requires_bearer_token(): void
    {
        // Lepas autentikasi → harus 401, bukan redirect ke halaman login web.
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/fhir/metadata')->assertUnauthorized();
        $this->getJson('/api/fhir/Patient')->assertUnauthorized();
    }

    public function test_fhir_accepts_real_sanctum_bearer_token(): void
    {
        $this->app['auth']->forgetGuards();

        $plain = $this->user->createToken('EMR RSUD', ['fhir:read'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $plain)
            ->getJson('/api/fhir/metadata')
            ->assertOk()
            ->assertJsonPath('resourceType', 'CapabilityStatement');

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $this->user->id,
            'name' => 'EMR RSUD',
        ]);
    }

    public function test_issue_token_command_creates_token(): void
    {
        $this->artisan('orp:issue-token', [
            'email' => $this->user->email,
            '--name' => 'EMR Test',
            '--expires' => 30,
        ])->assertSuccessful();

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $this->user->id,
            'name' => 'EMR Test',
        ]);
    }

    public function test_metadata_returns_capability_statement(): void
    {
        $this->getJson('/api/fhir/metadata')
            ->assertOk()
            ->assertJsonPath('resourceType', 'CapabilityStatement')
            ->assertJsonPath('fhirVersion', '4.0.1')
            ->assertHeader('Content-Type', 'application/fhir+json; charset=UTF-8');
    }

    public function test_patient_bundle_and_read(): void
    {
        $patient = Patient::create(['name' => 'FHIR Person', 'gender' => 'female', 'birth_date' => '1992-01-01']);

        $this->getJson('/api/fhir/Patient?name=Person')
            ->assertOk()
            ->assertJsonPath('resourceType', 'Bundle')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('entry.0.resource.name.0.text', 'FHIR Person');

        $this->getJson("/api/fhir/Patient/{$patient->id}")
            ->assertOk()
            ->assertJsonPath('resourceType', 'Patient')
            ->assertJsonPath('gender', 'female');
    }

    public function test_service_request_from_order(): void
    {
        $patient = Patient::create(['name' => 'SR Person']);
        $procedure = Procedure::where('code', 'R-CHEST-1V')->firstOrFail();
        $order = Order::create(['patient_id' => $patient->id, 'procedure_id' => $procedure->id]);

        $this->getJson('/api/fhir/ServiceRequest?accession=' . $order->accession_number)
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('entry.0.resource.resourceType', 'ServiceRequest')
            ->assertJsonPath('entry.0.resource.identifier.0.value', $order->accession_number)
            ->assertJsonPath('entry.0.resource.status', 'active');
    }

    public function test_imaging_study_bundle(): void
    {
        $patient = Patient::create(['name' => 'IS Person']);
        $procedure = Procedure::where('code', 'R-CHEST-1V')->firstOrFail();
        $order = Order::create(['patient_id' => $patient->id, 'procedure_id' => $procedure->id]);

        $study = Study::create([
            'order_id' => $order->id,
            'patient_id' => $patient->id,
            'accession_number' => $order->accession_number,
            'study_instance_uid' => '1.2.3.4.99',
            'series_instance_uid' => '1.2.3.5.99',
            'sop_instance_uid' => '1.2.3.6.99',
            'modality' => 'CR',
            'study_description' => 'Thorax PA',
            'matched' => true,
        ]);

        $this->getJson('/api/fhir/ImagingStudy?accession=' . $order->accession_number)
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('entry.0.resource.resourceType', 'ImagingStudy')
            ->assertJsonPath('entry.0.resource.modality.0.code', 'CR');
    }

    public function test_diagnostic_report_from_report(): void
    {
        $patient = Patient::create(['name' => 'DR Person']);
        $procedure = Procedure::where('code', 'R-CHEST-1V')->firstOrFail();
        $order = Order::create(['patient_id' => $patient->id, 'procedure_id' => $procedure->id]);
        $order->walkTo(\App\Enums\OrderStatus::Completed);

        $report = Report::create([
            'order_id' => $order->id,
            'findings' => 'Corakan paru normal.',
            'impression' => 'Normal.',
        ]);
        $report->transitionTo(ReportStatus::Dictated);

        $this->getJson('/api/fhir/DiagnosticReport?status=DICTATED')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('entry.0.resource.resourceType', 'DiagnosticReport')
            ->assertJsonPath('entry.0.resource.status', 'preliminary')
            ->assertJsonPath('entry.0.resource.conclusion', 'Normal.');
    }
}