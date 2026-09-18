<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Hasil inferensi AI per study (M6).
 * Status: PENDING → PROCESSING → COMPLETED | FAILED.
 */
class AiResult extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'study_id',
        'order_id',
        'status',
        'model_name',
        'pathologies',
        'findings',
        'raw_report',
        'error',
        'started_at',
        'completed_at',
        'inference_ms',
    ];

    protected function casts(): array
    {
        return [
            'pathologies' => 'array',
            'findings'    => 'array',
            'raw_report'  => 'array',
            'started_at'  => 'datetime',
            'completed_at'=> 'datetime',
        ];
    }

    public function study()
    {
        return $this->belongsTo(Study::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function markProcessing(): bool
    {
        $this->status = 'PROCESSING';
        $this->started_at = now();

        return $this->save();
    }

    public function markCompleted(array $rawReport, int $elapsedMs = 0): bool
    {
        $this->status = 'COMPLETED';
        $this->pathologies = $rawReport['pathologies'] ?? null;
        $this->findings = $rawReport['findings'] ?? null;
        $this->raw_report = $rawReport;
        $this->inference_ms = $elapsedMs;
        $this->completed_at = now();

        return $this->save();
    }

    public function markFailed(string $error): bool
    {
        $this->status = 'FAILED';
        $this->error = substr($error, 0, 1000);
        $this->completed_at = now();

        return $this->save();
    }
}