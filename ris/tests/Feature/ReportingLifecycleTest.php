<?php

namespace Tests\Feature;

use App\Enums\ReportStatus;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Modality;
use App\Models\Order;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $radiologist;
    private Order $completedOrder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->seed(\Database\Seeders\ProcedureSeeder::class);
        $this->seed(\Database\Seeders\ModalitySeeder::class);

        $this->radiologist = User::factory()->create(['email' => 'radio@domain.test']);
        $this->radiologist->assignRole(Role::Radiologist->value);
        $this->actingAs($this->radiologist);

        $patient = Patient::create(['name' => 'Report Person']);
        $procedure = Procedure::where('code', 'R-CHEST-1V')->firstOrFail();
        $this->completedOrder = Order::create([
            'patient_id' => $patient->id,
            'procedure_id' => $procedure->id,
        ]);
        $this->completedOrder->walkTo(\App\Enums\OrderStatus::Completed);
    }

    public function test_reports_api_requires_web_session(): void
    {
        // Endpoint SPA memakai grup `web` + auth (lihat routes/api.php): tanpa sesi
        // harus 401 JSON — bukan redirect login, bukan 200.
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/reports')->assertUnauthorized();
        $this->getJson('/api/orders/awaiting-report')->assertUnauthorized();
        $this->getJson('/api/ai-results')->assertUnauthorized();
    }

    public function test_report_number_and_default_status(): void
    {
        $report = Report::create(['order_id' => $this->completedOrder->id]);

        $this->assertMatchesRegularExpression('/^RPT-\d{14}-[A-Z0-9]{4}$/', $report->report_number);
        $this->assertTrue($report->status === ReportStatus::Draft);
    }

    public function test_report_lifecycle_dictated_verified_final(): void
    {
        $report = Report::create([
            'order_id' => $this->completedOrder->id,
            'radiologist_id' => $this->radiologist->id,
            'findings' => 'Corakan paru normal.',
            'impression' => 'Tidak ditemukan kelainan.',
        ]);

        $this->assertTrue($report->transitionTo(ReportStatus::Dictated));
        $this->assertTrue($report->status === ReportStatus::Dictated);
        $this->assertNotNull($report->dictated_at);

        $this->assertTrue($report->transitionTo(ReportStatus::Verified));
        $this->assertTrue($report->status === ReportStatus::Verified);
        $this->assertNotNull($report->verified_at);

        $this->assertTrue($report->transitionTo(ReportStatus::Final));
        $this->assertTrue($report->status === ReportStatus::Final);
        $this->assertNotNull($report->finalized_at);

        // FINAL tidak bisa mundur / diubah lagi
        $this->assertFalse($report->transitionTo(ReportStatus::Verified));
        $this->assertFalse($report->status->isEditable());
    }

    public function test_illegal_transition_is_rejected(): void
    {
        $report = Report::create(['order_id' => $this->completedOrder->id]);

        // DRAFT langsung VERIFIED tidak diizinkan (harus lewat DICTATED)
        $this->assertFalse($report->transitionTo(ReportStatus::Verified));
        $this->assertTrue($report->status === ReportStatus::Draft);
    }

    public function test_report_can_be_cancelled_from_editable_states(): void
    {
        $report = Report::create(['order_id' => $this->completedOrder->id]);
        $this->assertTrue($report->transitionTo(ReportStatus::Dictated));
        $this->assertTrue($report->transitionTo(ReportStatus::Cancelled));
        $this->assertTrue($report->status === ReportStatus::Cancelled);
    }

    public function test_api_create_update_and_transition(): void
    {
        $patient = Patient::create(['name' => 'API Person']);
        $procedure = Procedure::where('code', 'R-CHEST-1V')->firstOrFail();
        $order = Order::create(['patient_id' => $patient->id, 'procedure_id' => $procedure->id]);

        $res = $this->postJson('/api/reports', [
            'order_id' => $order->id,
            'findings' => 'Awal',
            'impression' => 'Kesan awal',
        ]);
        $res->assertStatus(201)->assertJsonPath('order_id', $order->id);

        $reportId = $res->json('id');

        // Edit masih boleh (DRAFT)
        $res = $this->putJson("/api/reports/{$reportId}", ['impression' => 'Kesan revisi']);
        $res->assertStatus(200)->assertJsonPath('impression', 'Kesan revisi');

        // Transisi DICTATED → VERIFIED
        $this->postJson("/api/reports/{$reportId}/transition", ['target' => 'DICTATED'])
            ->assertStatus(200)->assertJsonPath('status', 'DICTATED');
        $this->postJson("/api/reports/{$reportId}/transition", ['target' => 'VERIFIED'])
            ->assertStatus(200)->assertJsonPath('status', 'VERIFIED');

        // Setelah VERIFIED, edit ditolak
        $this->putJson("/api/reports/{$reportId}", ['impression' => 'Tidak boleh'])
            ->assertStatus(422);

        // Transisi ilegal ditolak (VERIFIED → DICTATED)
        $this->postJson("/api/reports/{$reportId}/transition", ['target' => 'DICTATED'])
            ->assertStatus(422);
    }

    public function test_report_audit_logged(): void
    {
        $report = Report::create(['order_id' => $this->completedOrder->id]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'report.created',
            'auditable_type' => Report::class,
            'auditable_id' => $report->id,
        ]);
    }
}