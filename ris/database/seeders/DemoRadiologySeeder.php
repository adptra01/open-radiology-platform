<?php

namespace Database\Seeders;

use App\Enums\AppointmentStatus;
use App\Enums\OrderPriority;
use App\Enums\OrderStatus;
use App\Models\Appointment;
use App\Models\Modality;
use App\Models\Order;
use App\Models\PacsSource;
use App\Models\Patient;
use App\Models\Procedure;
use Illuminate\Database\Seeder;

/**
 * Data demo untuk pengujian end-to-end M2/M3:
 * 3 pasien + 3 order (REQUESTED, SCHEDULED, COMPLETED) + 1 appointment.
 * Sejak M4: + 1 PACS eksternal aktif (Orthanc/dev) untuk antrean STOW.
 */
class DemoRadiologySeeder extends Seeder
{
    public function run(): void
    {
        $patients = [
            ['name' => 'Ahmad Fauzi', 'birth_date' => '1988-03-12', 'gender' => 'male', 'phone' => '0812-3456-7801', 'identity_number' => '3201011203880001'],
            ['name' => 'Maria Goreti', 'birth_date' => '1995-07-25', 'gender' => 'female', 'phone' => '0813-9876-5402', 'identity_number' => '3201026507950002'],
            ['name' => 'Joko Susilo', 'birth_date' => '1972-11-30', 'gender' => 'male', 'phone' => '0812-5555-1212', 'identity_number' => '3201033011720003'],
        ];

        $patientModels = [];
        foreach ($patients as $p) {
            $patientModels[] = Patient::create($p);
        }

        $chest = Procedure::where('code', 'R-CHEST-1V')->firstOrFail();
        $ctHead = Procedure::where('code', 'CT-HEAD-PLAIN')->firstOrFail();
        $cr = Modality::where('ae_title', 'ORPCR1')->firstOrFail();

        // M4: PACS eksternal demo (dev default — endpoint STOW diharapkan tersedia).
        // Base URL + kredensial dari config/ris.php (ORP_ORTHANC_*) supaya cocok
        // dengan Orthanc ber-auth: dev → host.docker.internal:8042, produksi →
        // http://orthanc:8042 (jaringan compose).
        $orthanc = rtrim((string) config('ris.orthanc.base_url'), '/');
        $pacs = PacsSource::firstOrCreate(
            ['name' => 'Orthanc Dev'],
            [
                'ae_title' => 'ORTHANC',
                'host' => parse_url($orthanc, PHP_URL_HOST) ?: 'orthanc',
                'port' => parse_url($orthanc, PHP_URL_PORT) ?: 8042,
                'base_url' => $orthanc,
                'qido_url' => $orthanc . '/dicom-web',
                'wado_url' => $orthanc . '/dicom-web',
                'stow_url' => $orthanc . '/dicom-web/studies',
                'username' => config('ris.orthanc.username'),
                'password' => config('ris.orthanc.password'),
                'is_active' => true,
            ],
        );

        Order::create([
            'patient_id' => $patientModels[0]->id,
            'procedure_id' => $chest->id,
            'modality_id' => $cr->id,
            'priority' => OrderPriority::Routine,
            'status' => OrderStatus::Requested,
            'scheduled_at' => now()->addDay(),
        ]);

        $scheduled = Order::create([
            'patient_id' => $patientModels[1]->id,
            'procedure_id' => $chest->id,
            'modality_id' => $cr->id,
            'priority' => OrderPriority::Urgent,
            'status' => OrderStatus::Scheduled,
            'scheduled_at' => now()->addHours(3),
        ]);

        Appointment::create([
            'order_id' => $scheduled->id,
            'modality_id' => $cr->id,
            'scheduled_at' => $scheduled->scheduled_at,
            'status' => AppointmentStatus::Confirmed,
        ]);

        Order::create([
            'patient_id' => $patientModels[2]->id,
            'procedure_id' => $ctHead->id,
            'priority' => OrderPriority::Stat,
            'status' => OrderStatus::Completed,
            'requested_at' => now()->subDay(),
            'scheduled_at' => now()->subDay()->addHour(),
            'completed_at' => now()->subDay()->addHours(2),
        ]);
    }
}