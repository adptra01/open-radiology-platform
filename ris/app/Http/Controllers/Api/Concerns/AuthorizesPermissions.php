<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Http\Request;

/**
 * Pemeriksaan izin RBAC (Spatie Permission) untuk endpoint SPA.
 *
 * Semua permission yang dipakai di sini berasal dari
 * `Database\Seeders\RolesAndPermissionsSeeder` — jangan mengarang nama baru
 * tanpa menambahkannya ke seeder, karena role non-admin tidak akan pernah
 * mendapat izin tersebut.
 */
trait AuthorizesPermissions
{
    /**
     * Batalkan request dengan 403 JSON bila user tidak punya izin.
     */
    protected function authorizePermission(Request $request, string $permission): void
    {
        abort_unless(
            $request->user()?->can($permission),
            403,
            "Butuh izin: {$permission}",
        );
    }
}
