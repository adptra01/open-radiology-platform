<?php

namespace App\Models;

use App\Enums\OrderPriority;
use App\Enums\OrderStatus;
use App\Services\IdentifierService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'order_number',
        'accession_number',
        'patient_id',
        'referring_doctor_id',
        'procedure_id',
        'modality_id',
        'priority',
        'status',
        'status_note',
        'requested_at',
        'scheduled_at',
        'completed_at',
        'facility_id',
    ];

    protected function casts(): array
    {
        return [
            'priority' => OrderPriority::class,
            'status' => OrderStatus::class,
            'requested_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            $svc = app(IdentifierService::class);
            if (blank($order->order_number)) {
                $order->order_number = $svc->nextOrderNumber();
            }
            if (blank($order->accession_number)) {
                $order->accession_number = $svc->nextAccession();
            }
            if (blank($order->priority)) {
                $order->priority = OrderPriority::Routine;
            }
            if (blank($order->status)) {
                $order->status = OrderStatus::Requested;
            }
            if (blank($order->requested_at)) {
                $order->requested_at = now();
            }
        });
    }

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function referringDoctor()
    {
        return $this->belongsTo(Doctor::class, 'referring_doctor_id');
    }

    public function procedure()
    {
        return $this->belongsTo(Procedure::class);
    }

    public function modality()
    {
        return $this->belongsTo(Modality::class);
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }

    public function studies()
    {
        return $this->hasMany(Study::class);
    }

    public function reports()
    {
        return $this->hasMany(Report::class);
    }

    /** Baris MPPS (N-CREATE/N-SET/N-ACTION) yang tertaut ke order ini. */
    public function mppsRecords()
    {
        return $this->hasMany(MppsRecord::class);
    }

    /**
     * Transisi status yang aman (validasi alur REQUESTED → … → COMPLETED,
     * lihat App\Enums\OrderStatus).
     */
    public function transitionTo(OrderStatus $target, ?string $note = null): bool
    {
        if (! $this->status->canTransitionTo($target)) {
            return false;
        }

        $this->status = $target;
        if ($note !== null) {
            $this->status_note = $note;
        }
        if ($target === OrderStatus::Completed) {
            $this->completed_at = now();
        }

        return $this->save();
    }

    /**
     * Berjalan otomatis melewati status perantara menuju target (BFS lewat
     * allowedTransitions). Dipakai mis. saat MPPS N-CREATE tiba padahal order
     * masih SCHEDULED → ARRIVED → IN_PROGRESS dieksekusi sekaligus.
     */
    public function walkTo(OrderStatus $target, ?string $note = null): bool
    {
        if ($this->status === $target) {
            return true;
        }

        $path = $this->pathBetween($this->status, $target);
        if ($path === null) {
            return false;
        }

        foreach (array_slice($path, 1) as $step) {
            $this->status = $step;
        }

        if ($note !== null) {
            $this->status_note = $note;
        }
        if ($target === OrderStatus::Completed) {
            $this->completed_at = now();
        }

        return $this->save();
    }

    private function pathBetween(OrderStatus $from, OrderStatus $to): ?array
    {
        $queue = [[$from, [$from]]];
        $visited = [];

        while ($queue !== []) {
            [$node, $path] = array_shift($queue);
            if ($node === $to) {
                return $path;
            }
            if (isset($visited[$node->value])) {
                continue;
            }
            $visited[$node->value] = true;
            foreach ($node->allowedTransitions() as $next) {
                $queue[] = [$next, [...$path, $next]];
            }
        }

        return null;
    }
}