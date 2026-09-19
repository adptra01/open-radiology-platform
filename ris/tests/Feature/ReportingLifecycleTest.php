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

    public function test_reports_endpoints_require_permission(): void
    {
        // M12.1 B1: user tanpa reports.* harus 403 di semua endpoint (backend, bukan UI).
        $plain = User::factory()->create(['email' => 'plain@domain.test']);
        $this->actingAs($plain);

        $report = Report::create(['order_id' => $this->completedOrder->id]);

        $this->getJson('/api/reports')->assertForbidden();
        $this->postJson('/api/reports', ['order_id' => $this->completedOrder->id])->assertForbidden();
        $this->putJson("/api/reports/{$report->id}", ['impression' => 'x'])->assertForbidden();
        $this->postJson("/api/reports/{$report->id}/transition", ['target' => 'DICTATED'])->assertForbidden();
        $this->postJson("/api/reports/{$report->id}/amendments", ['addendum' => 'x'])->assertForbidden();
        $this->deleteJson("/api/reports/{$report->id}")->assertForbidden();
    }

    public function test_destroy_final_rejected_destroy_draft_allowed(): void
    {
        // M12.1 B4: FINAL immutable — hapus ditolak.
        $final = Report::create(['order_id' => $this->completedOrder->id]);
        $final->transitionTo(ReportStatus::Dictated);
        $final->transitionTo(ReportStatus::Verified);
        $final->transitionTo(ReportStatus::Final);

        $this->deleteJson("/api/reports/{$final->id}")->assertStatus(422);
        $this->assertDatabaseHas('reports', ['id' => $final->id]);

        $draft = Report::create(['order_id' => $this->completedOrder->id]);
        $this->deleteJson("/api/reports/{$draft->id}")->assertOk();
        $this->assertSoftDeleted('reports', ['id' => $draft->id]);
    }

    public function test_amend_final_creates_child_record(): void
    {
        // M12.1 B4: amendment = record BARU (parent utuh), lahir DRAFT.
        $final = Report::create([
            'order_id' => $this->completedOrder->id,
            'findings' => 'Asli',
            'impression' => 'Kesan asli',
        ]);
        $final->transitionTo(ReportStatus::Dictated);
        $final->transitionTo(ReportStatus::Verified);
        $final->transitionTo(ReportStatus::Final);

        $res = $this->postJson("/api/reports/{$final->id}/amendments", [
            'addendum' => 'Koreksi: terdapat infiltrat lobus kanan.',
        ]);
        $res->assertStatus(201)->assertJsonPath('parent_report_id', $final->id);

        // Parent tidak tersentuh.
        $final->refresh();
        $this->assertSame('Asli', $final->findings);
        $this->assertTrue($final->status === ReportStatus::Final);

        $child = Report::findOrFail($res->json('id'));
        $this->assertTrue($child->status === ReportStatus::Draft);
        $this->assertSame('Koreksi: terdapat infiltrat lobus kanan.', $child->addendum);
        $this->assertCount(1, $final->amendments);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'report.amended',
            'auditable_id' => $child->id,
        ]);

        // Amendment pada report non-FINAL ditolak.
        $draft = Report::create(['order_id' => $this->completedOrder->id]);
        $this->postJson("/api/reports/{$draft->id}/amendments", ['addendum' => 'x'])->assertStatus(422);
    }

    public function test_awaiting_report_only_final_counts_done(): void
    {
        // M12.1 B4: DRAFT/CANCELLED tidak dihitung selesai.
        $mkOrder = function (): Order {
            $patient = Patient::create(['name' => 'Await Person']);
            $procedure = Procedure::where('code', 'R-CHEST-1V')->firstOrFail();
            $order = Order::create(['patient_id' => $patient->id, 'procedure_id' => $procedure->id]);
            $order->walkTo(\App\Enums\OrderStatus::Completed);

            return $order;
        };

        $withDraft = $mkOrder();
        Report::create(['order_id' => $withDraft->id]); // DRAFT

        $withCancelled = $mkOrder();
        $cancelled = Report::create(['order_id' => $withCancelled->id]);
        $cancelled->transitionTo(ReportStatus::Dictated);
        $cancelled->transitionTo(ReportStatus::Cancelled);

        $withFinal = $mkOrder();
        $final = Report::create(['order_id' => $withFinal->id]);
        $final->transitionTo(ReportStatus::Dictated);
        $final->transitionTo(ReportStatus::Verified);
        $final->transitionTo(ReportStatus::Final);

        $ids = collect($this->getJson('/api/orders/awaiting-report')->json('data'))->pluck('id')->all();
        $this->assertContains($withDraft->id, $ids);
        $this->assertContains($withCancelled->id, $ids);
        $this->assertNotContains($withFinal->id, $ids);
        // completedOrder milik setUp (tanpa report) ikut terdaftar.
        $this->assertContains($this->completedOrder->id, $ids);
    }
}