<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Api\Concerns\AuthorizesPermissions;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Manajemen user & role (khusus Admin/SuperAdmin — tidak ada permission
 * `users.*` di seeder, jadi dicek lewat role).
 *
 * GET /api/users                  daftar user + role
 * GET /api/roles                  daftar role + permission-nya
 * PUT /api/users/{user}/roles     set role user (body: { roles: ["radiologist"] })
 */
class UserController extends Controller
{
    use AuthorizesPermissions;

    private function authorizeAdmin(Request $request): void
    {
        abort_unless(
            $request->user()?->hasAnyRole([Role::SuperAdmin->value, Role::Admin->value]),
            403,
            'Butuh role Admin/SuperAdmin.',
        );
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $users = User::query()
            ->with('roles:id,name')
            ->when($request->string('search')->toString(), fn ($q, $s) => $q->where(fn ($qq) => $qq
                ->where('name', 'like', "%{$s}%")
                ->orWhere('email', 'like', "%{$s}%")))
            ->when($request->string('role')->toString(), fn ($q, $r) => $q->whereHas('roles', fn ($rr) => $rr->where('name', $r)))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return response()->json($users);
    }

    public function roles(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $roles = SpatieRole::with('permissions:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (SpatieRole $role) => [
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->all(),
                'is_system' => in_array($role->name, array_column(Role::cases(), 'value'), true),
            ]);

        return response()->json(['data' => $roles]);
    }

    public function updateRoles(Request $request, User $user): JsonResponse
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'roles' => ['required', 'array'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
        ]);

        if ($user->is($request->user())) {
            return response()->json([
                'message' => 'Tidak bisa mengubah role akun sendiri (hindari terkunci di luar sistem).',
            ], 422);
        }

        $before = $user->getRoleNames()->all();
        $user->syncRoles($data['roles']);

        AuditLog::record('user.roles_updated', $user, [
            'before' => $before,
            'after' => $data['roles'],
        ]);

        return response()->json($user->load('roles:id,name'));
    }
}
