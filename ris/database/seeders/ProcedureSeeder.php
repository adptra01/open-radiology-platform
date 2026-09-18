<?php

namespace Database\Seeders;

use App\Models\Procedure;
use Illuminate\Database\Seeder;

class ProcedureSeeder extends Seeder
{
    /** Katalog prosedur dasar radiologi (kode internal ORP). */
    public function run(): void
    {
        $procedures = [
            ['code' => 'R-CHEST-1V', 'name' => 'Chest X-Ray 1 View', 'modality' => 'CR', 'body_part' => 'Chest'],
            ['code' => 'R-CHEST-2V', 'name' => 'Chest X-Ray 2 Views (PA + Lateral)', 'modality' => 'CR', 'body_part' => 'Chest'],
            ['code' => 'R-CERVICAL', 'name' => 'Cervical Spine X-Ray', 'modality' => 'CR', 'body_part' => 'Cervical Spine'],
            ['code' => 'R-THORACIC', 'name' => 'Thoracic Spine X-Ray', 'modality' => 'CR', 'body_part' => 'Thoracic Spine'],
            ['code' => 'R-LUMBAR', 'name' => 'Lumbar Spine X-Ray', 'modality' => 'CR', 'body_part' => 'Lumbar Spine'],
            ['code' => 'R-ABDOMEN-AP', 'name' => 'Abdomen AP Supine', 'modality' => 'CR', 'body_part' => 'Abdomen'],
            ['code' => 'R-EXTREMITY', 'name' => 'Extremity X-Ray (per ekstremitas)', 'modality' => 'CR', 'body_part' => 'Extremity'],
            ['code' => 'CT-HEAD-PLAIN', 'name' => 'CT Head Non-Contrast', 'modality' => 'CT', 'body_part' => 'Head'],
            ['code' => 'CT-CHEST', 'name' => 'CT Chest', 'modality' => 'CT', 'body_part' => 'Chest'],
            ['code' => 'CT-ABDOMEN', 'name' => 'CT Abdomen', 'modality' => 'CT', 'body_part' => 'Abdomen'],
            ['code' => 'MR-BRAIN', 'name' => 'MRI Brain', 'modality' => 'MR', 'body_part' => 'Brain'],
            ['code' => 'MR-KNEE', 'name' => 'MRI Knee', 'modality' => 'MR', 'body_part' => 'Knee'],
            ['code' => 'US-ABDOMEN', 'name' => 'US Abdomen', 'modality' => 'US', 'body_part' => 'Abdomen'],
            ['code' => 'US-OBSTETRIC', 'name' => 'US Obstetric', 'modality' => 'US', 'body_part' => 'Obstetric'],
            ['code' => 'MG-BILATERAL', 'name' => 'Mammography Bilateral', 'modality' => 'MG', 'body_part' => 'Breast'],
        ];

        foreach ($procedures as $p) {
            Procedure::updateOrCreate(['code' => $p['code']], $p);
        }
    }
}