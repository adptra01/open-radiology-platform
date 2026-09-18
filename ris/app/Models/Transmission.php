<?php

namespace App\Models;

use App\Enums\TransmissionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Satu baris = satu kiriman yang akan/ sudah dikirim ke PACS eksternal.
 * Diproses oleh App\Jobs\ProcessTransmission via queue database (M4).
 */
class Transmission extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'pacs_source_id',
        'study_id',
        'order_id',
        'transmission_type',
        'status',
        'attempts',
        'max_attempts',
        'payload',
        'error',
        'next_attempt_at',
        'sent_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TransmissionStatus::class,
            'payload' => 'array',
            'next_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function pacsSource()
    {
        return $this->belongsTo(PacsSource::class);
    }

    public function study()
    {
        return $this->belongsTo(Study::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function markSent(?string $detail = null): bool
    {
        $this->status = TransmissionStatus::Sent;
        $this->sent_at = now();
        $this->completed_at = now();
        $this->error = $detail ?: null;
        $this->next_attempt_at = null;

        return $this->save();
    }

    public function markFailed(string $error): bool
    {
        $this->status = TransmissionStatus::Failed;
        $this->error = $error;
        $this->completed_at = now();
        $this->next_attempt_at = null;

        return $this->save();
    }

    public function registerAttempt(): bool
    {
        $this->attempts++;
        $this->next_attempt_at = now()->addSeconds(min(300, 5 * (2 ** $this->attempts))); // backoff

        return $this->save();
    }
}