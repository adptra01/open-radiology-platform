<?php

namespace Database\Seeders;

use App\Models\Doctor;
use Illuminate\Database\Seeder;

class DoctorSeeder extends Seeder
{
    public function run(): void
    {
        $doctors = [
            ['name' => 'Dr. Budi Santoso, Sp.Rad', 'specialty' => 'Radiologi', 'license_number' => 'SIP-1001', 'is_radiologist' => true],
            ['name' => 'Dr. Siti Rahayu, Sp.PD', 'specialty' => 'Penyakit Dalam', 'license_number' => 'SIP-1002', 'is_radiologist' => false],
            ['name' => 'Dr. Andi Wijaya, Sp.B', 'specialty' => 'Bedah', 'license_number' => 'SIP-1003', 'is_radiologist' => false],
        ];

        foreach ($doctors as $d) {
            Doctor::updateOrCreate(['license_number' => $d['license_number']], $d);
        }
    }
}