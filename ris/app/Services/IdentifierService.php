<?php

namespace App\Services;

use App\Models\AiRun;
use App\Models\Order;
use App\Models\Patient;
use App\Models\Report;
use Illuminate\Support\Str;

/**
 * Generator identitas unik (keputusan M2, Rencana §7):
 *   MRN       = MRN-YYYYMMDD-XXXX (contoh: MRN-20260916-0001)  — VR LO (≤64), bebas
 *   Accession = ACC-YYMMDD-XXXX   (contoh: ACC-260916-0001)    — VR SH (≤16), WAJIB pendek
 *
 * ⚠️ Accession Number di DICOM ber-VR **SH (Short String, maks 16 karakter)**.
 * Format lama `ACC-YYYYMMDD-XXXX` = 17 karakter → dilanggar; pydicom memperingatkan
 * "value length (17) exceeds the maximum length of 16 allowed for VR SH" dan modalitas
 * produksi bisa menolak. Format sekarang `ACC-YYMMDD-XXXX` = **15 karakter**
 * (3 prefix + 1 + 6 tanggal + 1 + 4 acak) → aman dengan 1 karakter headroom,
 * kapasitas 10.000/hari, dan tanggal tetap terbaca.
 *
 * Kolom unik di DB (patients.mrn, orders.accession_number) menjadi pengaman
 * akhir; metode ini melakukan retry bila terjadi tabrakan acak.
 */
class IdentifierService
{
    /** Batas VR SH pada DICOM (Short String). */
    public const ACCESSION_MAX_LENGTH = 16;

    public function nextMrn(): string
    {
        do {
            $mrn = 'MRN-' . now()->format('Ymd') . '-' . Str::padLeft((string) random_int(0, 9999), 4, '0');
        } while (Patient::withTrashed()->where('mrn', $mrn)->exists());

        return $mrn;
    }

    /**
     * Accession Number — VR SH, maks 16 karakter (lihat catatan kelas).
     * Format: ACC-YYMMDD-XXXX (15 karakter).
     */
    public function nextAccession(): string
    {
        do {
            $acc = 'ACC-' . now()->format('ymd') . '-' . Str::padLeft((string) random_int(0, 9999), 4, '0');
        } while (Order::withTrashed()->where('accession_number', $acc)->exists());

        return $acc;
    }

    /**
     * Order Number — juga dikirim sebagai RequestedProcedureID MWL (VR SH,
     * maks 16 karakter, lihat catatan kelas).
     * Format: ORD-YYMMDD-XXXX (15 karakter, 10.000/hari, retry bila tabrakan).
     */
    public function nextOrderNumber(): string
    {
        do {
            $no = 'ORD-' . now()->format('ymd') . '-' . Str::padLeft((string) random_int(0, 9999), 4, '0');
        } while (Order::withTrashed()->where('order_number', $no)->exists());

        return $no;
    }

    public function nextReportNumber(): string
    {
        return 'RPT-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(4));
    }

    /**
     * AI Run ID — human-readable untuk API/audit.
     * Format: AIR-YYMMDD-XXXX (15 karakter, konsisten dengan ACC-/ORD-YYMMDD-XXXX,
     * kapasitas 10.000/hari, retry bila tabrakan).
     */
    public function nextRunId(): string
    {
        do {
            $id = 'AIR-' . now()->format('ymd') . '-' . Str::padLeft((string) random_int(0, 9999), 4, '0');
        } while (AiRun::withTrashed()->where('run_id', $id)->exists());

        return $id;
    }
}