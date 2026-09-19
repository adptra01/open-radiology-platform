<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Study extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'accession_number',
        'study_instance_uid',
        'series_instance_uid',
        'sop_instance_uid',
        'sop_class_uid',
        'modality',
        'study_description',
        'study_date',
        'study_time',
        'patient_id',
        'order_id',
        'file_path',
        'matched',
        'matched_at',
    ];

    protected function casts(): array
    {
        return [
            'matched' => 'boolean',
            'matched_at' => 'datetime',
        ];
    }

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function reports()
    {
        return $this->hasMany(Report::class);
    }

    public function transmissions()
    {
        return $this->hasMany(Transmission::class);
    }

    public function aiResults()
    {
        return $this->hasMany(AiResult::class);
    }

    /**
     * Antrekan transmisi study ke PACS (STOW-RS) bila ada file fisik
     * dan ada PACS aktif dengan stow_url. Dipanggil otomatis dari
     * DicomController::storeStudy (M4).
     */
    public function queueTransmission(): ?Transmission
    {
        $pacs = \App\Models\PacsSource::active()->whereNotNull('stow_url')->first();
        if (! $pacs || blank($this->file_path)) {
            // M12.2: jangan silent — operator harus bisa melihat kenapa tak antre.
            \Illuminate\Support\Facades\Log::warning('Transmisi dilewati (tanpa PACS/file)', [
                'study_id' => $this->id,
                'has_pacs' => (bool) $pacs,
                'has_file' => ! blank($this->file_path),
            ]);

            return null;
        }

        $transmission = $this->transmissions()
            ->where('status', '!=', 'SENT')
            ->where('transmission_type', 'STOW')
            ->first();

        if ($transmission) {
            return $transmission;
        }

        $transmission = Transmission::create([
            'pacs_source_id' => $pacs->id,
            'study_id' => $this->id,
            'order_id' => $this->order_id,
            'transmission_type' => 'STOW',
            'payload' => ['file_path' => $this->file_path],
        ]);

        \App\Jobs\ProcessTransmission::dispatch($transmission);

        return $transmission;
    }
}