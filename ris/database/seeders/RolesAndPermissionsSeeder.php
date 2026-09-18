<?php

namespace Database\Seeders;

use App\Enums\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;

class RolesAndPermissionsSeeder extends Seeder
{
    private const ROLES = [
        Role::SuperAdmin->value => '*',
        Role::Admin->value => '*',
        Role::Registration->value => [
            'patients.view', 'patients.create', 'patients.edit', 'patients.merge',
            'referring-doctors.view', 'referring-doctors.create', 'referring-doctors.edit',
            'orders.view', 'orders.create', 'orders.edit', 'orders.cancel',
            'accessions.view',
        ],
        Role::Scheduler->value => [
            'patients.view', 'orders.view', 'modalities.view', 'pacs.view',
            'scheduling.view', 'scheduling.create', 'scheduling.edit', 'scheduling.confirm', 'scheduling.reschedule', 'scheduling.check-in',
        ],
        Role::Radiographer->value => [
            'patients.view', 'orders.view', 'scheduling.view', 'modalities.view', 'pacs.view',
            'mwl.query', 'mwl.generate', 'mpps.process', 'transmission.view',
        ],
        Role::Radiologist->value => [
            'patients.view', 'orders.view', 'modalities.view', 'pacs.view',
            'reports.view', 'reports.create', 'reports.edit', 'reports.sign',
        ],
        Role::ReferringDoctor->value => [
            'patients.view', 'orders.view', 'reports.view',
        ],
        Role::PacsAdmin->value => [
            'pacs.view', 'pacs.create', 'pacs.edit',
            'modalities.view', 'modalities.create', 'modalities.edit',
            'transmission.view', 'transmission.retry',
        ],
        Role::Auditor->value => [
            'audit.view',
        ],
    ];

    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = collect(self::ROLES)
            ->flatMap(fn (array|string $perms) => $perms === '*' ? [] : $perms)
            ->unique()
            ->values();

        $permissions->each(fn (string $permission) => Permission::findOrCreate($permission));

        // Flush cache again — findOrCreate populates the PermissionRegistrar cache
        // with partial data; givePermissionTo/syncPermissions then fail with
        // "permission not found" because the stale cache has fewer entries.
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (self::ROLES as $name => $perms) {
            $role = SpatieRole::findOrCreate($name);

            if ($perms === '*') {
                $role->givePermissionTo($permissions->all());
            } else {
                $role->syncPermissions($perms);
            }
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
}