<?php

namespace App\Models;

use App\Enums\Gender;
use App\Services\IdentifierService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Patient extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'mrn',
        'name',
        'birth_date',
        'gender',
        'phone',
        'address',
        'identity_number',   // NIK/surat identitas lain
        'identity_type',
        'facility_id',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'gender' => Gender::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Patient $patient) {
            if (blank($patient->mrn)) {
                $patient->mrn = app(IdentifierService::class)->nextMrn();
            }
        });
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}