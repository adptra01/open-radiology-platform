<?php

namespace Database\Seeders;

use App\Models\Modality;
use Illuminate\Database\Seeder;

class ModalitySeeder extends Seeder
{
    /** Modalitas uji (AE title menyesuaikan konfigurasi adapter/adapter C-STORE). */
    public function run(): void
    {
        $modalities = [
            ['name' => 'CR Radiologi 1', 'ae_title' => 'ORPCR1', 'host' => '192.168.1.10', 'port' => 104, 'modality_type' => 'CR', 'location' => 'Ruang Radiologi 1', 'is_online' => false],
            ['name' => 'CT Scan RS', 'ae_title' => 'ORPCT1', 'host' => '192.168.1.20', 'port' => 104, 'modality_type' => 'CT', 'location' => 'Ruang CT', 'is_online' => false],
            ['name' => 'MRI RS', 'ae_title' => 'ORPMR1', 'host' => '192.168.1.30', 'port' => 104, 'modality_type' => 'MR', 'location' => 'Ruang MRI', 'is_online' => false],
            ['name' => 'USG Poliklinik', 'ae_title' => 'ORPUS1', 'host' => '192.168.1.40', 'port' => 104, 'modality_type' => 'US', 'location' => 'Poliklinik', 'is_online' => false],
        ];

        foreach ($modalities as $m) {
            Modality::updateOrCreate(['ae_title' => $m['ae_title']], $m);
        }
    }
}