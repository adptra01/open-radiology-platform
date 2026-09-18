<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Doctor;
use App\Models\Modality;
use App\Models\Order;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API master data SPA (M7): pasien, dokter, modalitas, katalog prosedur,
 * audit log, manajemen user/role, dashboard.
 */
class MasterDataApiTest extends TestCase
{
    use RefreshDatabase;

    private User $registration;
    private User $auditor;
    private User $pacsAdmin;
    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->seed(\Database\Seeders\ProcedureSeeder::class);
        $this->seed(\Database\Seeders\ModalitySeeder::class);

        $this->registration = $this->userWithRole('reg@domain.test', Role::Registration);
        $this->auditor = $this->userWithRole('auditor@domain.test', Role::Auditor);
        $this->pacsAdmin = $this->userWithRole('pacs@domain.test', Role::PacsAdmin);
        $this->superAdmin = $this->userWithRole('boss@domain.test', Role::SuperAdmin);
    }

    private function userWithRole(string $email, Role $role): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->assignRole($role->value);

        return $user;
    }

    public function test_master_data_endpoints_require_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        foreach (['/api/patients', '/api/doctors', '/api/modalities', '/api/dashboard', '/api/audit-logs', '/api/users'] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
    }

    public function test_registration_can_create_and_update_patient(): void
    {
        $this->actingAs($this->registration)
            ->postJson('/api/patients', [
                'name' => 'Budi Santoso',
                'gender' => 'male',
                'birth_date' => '1980-05-01',
                'phone' => '0812345',
            ])
            ->assertCreated()
            ->assertJsonPath('name', 'Budi Santoso');

        $patient = Patient::firstWhere('name', 'Budi Santoso');
        $this->assertNotNull($patient->mrn, 'MRN harus di-generate otomatis');

        $this->getJson('/api/patients?search=Budi')
            ->assertOk()
            ->assertJsonPath('data.0.mrn', $patient->mrn);

        $this->actingAs($this->registration)
            ->putJson("/api/patients/{$patient->id}", ['phone' => '0899999'])
            ->assertOk()
            ->assertJsonPath('phone', '0899999');

        $this->assertDatabaseHas('audit_logs', ['action' => 'patient.updated', 'auditable_id' => $patient->id]);
    }

    public function test_patient_validation_rejects_unknown_gender(): void
    {
        $this->actingAs($this->registration)
            ->postJson('/api/patients', ['name' => 'X', 'gender' => 'laki-laki'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('gender');
    }

    public function test_auditor_cannot_touch_patient_master_data(): void
    {
        $this->actingAs($this->auditor)
            ->getJson('/api/patients')
            ->assertForbidden();

        $this->actingAs($this->auditor)
            ->postJson('/api/patients', ['name' => 'Nope'])
            ->assertForbidden();
    }

    public function test_patient_delete_blocked_while_orders_exist(): void
    {
        $patient = Patient::create(['name' => 'Punya Order']);
        Order::create([
            'patient_id' => $patient->id,
            'procedure_id' => Procedure::where('code', 'R-CHEST-1V')->firstOrFail()->id,
        ]);

        $this->actingAs($this->registration)
            ->deleteJson("/api/patients/{$patient->id}")
            ->assertUnprocessable();

        $this->assertNotSoftDeleted('patients', ['id' => $patient->id]);
    }

    public function test_patient_merge_moves_orders_and_soft_deletes_source(): void
    {
        $source = Patient::create(['name' => 'Budi (duplikat)']);
        $target = Patient::create(['name' => 'Budi Santoso']);

        $order = Order::create([
            'patient_id' => $source->id,
            'procedure_id' => Procedure::where('code', 'R-CHEST-1V')->firstOrFail()->id,
        ]);

        $this->actingAs($this->registration)
            ->postJson("/api/patients/{$target->id}/merge", ['source_id' => $source->id])
            ->assertOk()
            ->assertJsonPath('orders_moved', 1);

        $this->assertSame($target->id, $order->fresh()->patient_id);
        $this->assertSoftDeleted('patients', ['id' => $source->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'patient.merged', 'auditable_id' => $target->id]);
    }

    public function test_patient_merge_rejects_self_and_trashed_source(): void
    {
        $patient = Patient::create(['name' => 'Sendiri']);

        $this->actingAs($this->registration)
            ->postJson("/api/patients/{$patient->id}/merge", ['source_id' => $patient->id])
            ->assertUnprocessable();

        $trashed = Patient::create(['name' => 'Terhapus']);
        $trashed->delete();

        $this->actingAs($this->registration)
            ->postJson("/api/patients/{$patient->id}/merge", ['source_id' => $trashed->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('source_id');
    }

    public function test_doctor_crud_and_permission_scope(): void
    {
        $this->actingAs($this->registration)
            ->postJson('/api/doctors', [
                'name' => 'dr. Siti',
                'specialty' => 'Radiologi',
                'is_radiologist' => true,
            ])
            ->assertCreated();

        $doctor = Doctor::firstWhere('name', 'dr. Siti');
        $this->assertTrue($doctor->is_radiologist);

        $this->actingAs($this->registration)
            ->putJson("/api/doctors/{$doctor->id}", ['phone' => '0812'])
            ->assertOk();

        $this->actingAs($this->auditor)
            ->getJson('/api/doctors')
            ->assertForbidden();

        $this->actingAs($this->registration)
            ->deleteJson("/api/doctors/{$doctor->id}")
            ->assertOk();
        $this->assertSoftDeleted('doctors', ['id' => $doctor->id]);
    }

    public function test_modality_crud_only_for_pacs_admin(): void
    {
        $this->actingAs($this->pacsAdmin)
            ->postJson('/api/modalities', [
                'name' => 'CT 128',
                'ae_title' => 'CT128',
                'host' => '10.0.0.9',
                'port' => 104,
                'modality_type' => 'CT',
            ])
            ->assertCreated();

        $modality = Modality::firstWhere('ae_title', 'CT128');

        // AE title duplikat ditolak.
        $this->actingAs($this->pacsAdmin)
            ->postJson('/api/modalities', ['name' => 'Lain', 'ae_title' => 'CT128'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ae_title');

        $this->actingAs($this->registration)
            ->postJson('/api/modalities', ['name' => 'Nope', 'ae_title' => 'NOPE'])
            ->assertForbidden();

        // Modalitas yang dipakai order tidak boleh dihapus.
        $patient = Patient::create(['name' => 'Pasien CT']);
        Order::create([
            'patient_id' => $patient->id,
            'procedure_id' => Procedure::where('code', 'R-CHEST-1V')->firstOrFail()->id,
            'modality_id' => $modality->id,
        ]);

        $this->actingAs($this->pacsAdmin)
            ->deleteJson("/api/modalities/{$modality->id}")
            ->assertUnprocessable();
    }

    public function test_procedure_catalog_is_read_only(): void
    {
        $this->actingAs($this->registration)
            ->getJson('/api/procedures?search=chest')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'code', 'name', 'modality']]]);

        // Tidak ada route POST untuk katalog prosedur.
        $this->actingAs($this->registration)
            ->postJson('/api/procedures', ['name' => 'Baru'])
            ->assertMethodNotAllowed();
    }

    public function test_audit_log_list_requires_audit_view(): void
    {
        AuditLog::create(['action' => 'order.created']);

        $this->actingAs($this->auditor)
            ->getJson('/api/audit-logs')
            ->assertOk()
            ->assertJsonPath('data.0.action', 'order.created');

        $this->actingAs($this->registration)
            ->getJson('/api/audit-logs')
            ->assertForbidden();
    }

    public function test_user_and_role_management_is_admin_only(): void
    {
        $target = $this->userWithRole('radiolog@domain.test', Role::Radiologist);

        $this->actingAs($this->registration)
            ->getJson('/api/users')
            ->assertForbidden();

        $this->actingAs($this->superAdmin)
            ->getJson('/api/users?search=radiolog')
            ->assertOk()
            ->assertJsonPath('data.0.email', 'radiolog@domain.test');

        $this->actingAs($this->superAdmin)
            ->getJson('/api/roles')
            ->assertOk()
            ->assertJsonFragment(['name' => Role::Auditor->value]);

        // Admin tidak boleh mengubah role akun sendiri.
        $this->actingAs($this->superAdmin)
            ->putJson("/api/users/{$this->superAdmin->id}/roles", ['roles' => [Role::Auditor->value]])
            ->assertUnprocessable();

        $this->actingAs($this->superAdmin)
            ->putJson("/api/users/{$target->id}/roles", ['roles' => [Role::PacsAdmin->value]])
            ->assertOk();

        $this->assertTrue($target->fresh()->hasRole(Role::PacsAdmin->value));
        $this->assertFalse($target->fresh()->hasRole(Role::Radiologist->value));
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.roles_updated', 'auditable_id' => $target->id]);
    }

    public function test_dashboard_sections_follow_permissions(): void
    {
        // Registration: orders.view + patients.view → workflow + patients, tanpa audit/pacs.
        $response = $this->actingAs($this->registration)->getJson('/api/dashboard')->assertOk();
        $this->assertArrayHasKey('workflow', $response->json('data'));
        $this->assertArrayHasKey('patients', $response->json('data'));
        $this->assertArrayNotHasKey('pacs', $response->json('data'));

        // Auditor: hanya audit.view → tidak ada bagian operasional.
        $this->actingAs($this->auditor)
            ->getJson('/api/dashboard')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }
}
