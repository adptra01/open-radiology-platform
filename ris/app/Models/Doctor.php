<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Doctor extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'specialty',
        'license_number',
        'phone',
        'email',
        'is_radiologist',
        'facility_id',
    ];

    protected function casts(): array
    {
        return [
            'is_radiologist' => 'boolean',
        ];
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'referring_doctor_id');
    }
}