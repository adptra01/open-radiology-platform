<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(ProcedureSeeder::class);
        $this->call(ModalitySeeder::class);
        $this->call(DoctorSeeder::class);

        $admin = User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'admin@testing.com',
        ]);
        $admin->assignRole(Role::SuperAdmin->value);

        $radiographer = User::factory()->create([
            'name' => 'Radiographer Demo',
            'email' => 'radio@testing.com',
        ]);
        $radiographer->assignRole(Role::Radiographer->value);

        $radiologist = User::factory()->create([
            'name' => 'Radiologist Demo',
            'email' => 'dr@testing.com',
        ]);
        $radiologist->assignRole(Role::Radiologist->value);

        $this->call(DemoRadiologySeeder::class);
    }
}