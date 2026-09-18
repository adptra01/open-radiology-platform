<?php

namespace App\Jobs;

use App\Enums\TransmissionStatus;
use App\Models\PacsSource;
use App\Models\Study;
use App\Models\Transmission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Transmisi antrean (M4) — kirim study/order ke PACS eksternal via STOW-RS.
 *
 * Alur:
 *  1. Cari pacs_source aktif (jika transmission belum menunjuk, gunakan default).
 *  2. Kirim file DICOM study (payload.file_path) via HTTP multipart ke stow_url.
 *  3. Sukses  → markSent.
 *  4. Gagal   → retry dengan backoff eksponensial (attempts++), habis → FAILED.
 *
 * Driver queue = database (PCNTL tidak ada di Windows — lihat Rencana §6b).
 */
class ProcessTransmission implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1; // retry manual via backoff (attempts di tabel)

    public int $timeout = 120;

    public function __construct(public Transmission $transmission)
    {
        //
    }

    public function handle(): void
    {
        // Hanya proses yang masih PENDING (hindari double-proses).
        if ($this->transmission->status->value !== TransmissionStatus::Pending->value) {
            return;
        }

        $this->transmission->status = TransmissionStatus::Sending;
        $this->transmission->save();

        $pacs = $this->transmission->pacsSource
            ?? PacsSource::active()->whereNotNull('stow_url')->first();

        if (! $pacs || blank($pacs->stow_url)) {
            $this->retryOrFail('Tidak ada PACS aktif dengan stow_url terkonfigurasi.');

            return;
        }

        $filePath = data_get($this->transmission->payload, 'file_path');

        try {
            $response = Http::timeout(90)->attach(
                'file',
                fopen($filePath, 'r'),
                basename((string) $filePath),
                ['Content-Type' => 'application/dicom']
            )->post($pacs->stow_url);

            if ($response->successful()) {
                $this->transmission->markSent("HTTP {$response->status()}");

                return;
            }

            $this->retryOrFail("HTTP {$response->status()}: {$response->body()}");
        } catch (\Throwable $e) {
            $this->retryOrFail($e->getMessage());
        }
    }

    private function retryOrFail(string $error): void
    {
        $this->transmission->error = substr($error, 0, 1000);
        $this->transmission->save();

        if ($this->transmission->attempts >= $this->transmission->max_attempts) {
            $this->transmission->markFailed($error);
            Log::warning('Transmisi DICOM gagal permanen', [
                'transmission_id' => $this->transmission->id,
                'error' => $error,
            ]);

            return;
        }

        $this->transmission->registerAttempt();
        $this->transmission->status = TransmissionStatus::Pending;
        $this->transmission->save();

        $this->release($this->transmission->next_attempt_at?->diffInSeconds(now()) ?? 15);
    }
}