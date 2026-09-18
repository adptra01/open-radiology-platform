<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Modality extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',              // nama alat (mis. "CR Ruang 1")
        'ae_title',          // DICOM Application Entity Title (unik, uppercase)
        'host',              // alamat IP/hostname modality
        'port',              // DICOM port (umumnya 104/11112)
        'modality_type',     // CR/CT/MR/US/MG...
        'location',
        'is_online',
        'facility_id',
    ];

    protected function casts(): array
    {
        return [
            'is_online' => 'boolean',
        ];
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }
}