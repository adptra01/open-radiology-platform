<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pendaftaran sumber PACS eksternal (onboarding, M2):
 * setiap PACS yang akan dihubungkan (Orthanc, dll) didaftarkan di sini
 * dengan URL QIDO/WADO/STOW serta AE title-nya.
 */
class PacsSource extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',                // nama PACS (mis. "Orthanc RS A")
        'ae_title',            // AE title PACS
        'host',
        'port',
        'base_url',            // root HTTP (mis. http://orthanc:8042)
        'qido_url',            // QIDO-RS endpoint
        'wado_url',            // WADO-RS endpoint
        'stow_url',            // STOW-RS endpoint
        'username',            // Basic auth (opsional, mis. Orthanc ber-auth)
        'password',
        'is_active',
        'facility_id',
    ];

    /** Password tidak pernah ikut serialisasi JSON (dipakai UI/API). */
    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'password' => 'encrypted',
        ];
    }

    /** True bila kredensial Basic auth tersedia. */
    public function hasCredentials(): bool
    {
        return filled($this->username) && filled($this->password);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function transmissions()
    {
        return $this->hasMany(Transmission::class);
    }
}