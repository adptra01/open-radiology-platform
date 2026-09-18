<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Appointment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'order_id',
        'modality_id',
        'scheduled_at',
        'status',
        'notes',
        'facility_id',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'status' => AppointmentStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Appointment $appointment) {
            if (blank($appointment->status)) {
                $appointment->status = AppointmentStatus::Scheduled;
            }
        });
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function modality()
    {
        return $this->belongsTo(Modality::class);
    }
}