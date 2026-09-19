<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Canonical AI run record (keputusan arsitektur final M10+).
 *
 * Satu baris = satu eksekusi satu task generik (task_id: tb-screening, ...).
 * Status eksplisit: queued → running → completed | failed | cancelled.
 * Tidak ada null = negative: hasil negatif = completed + classification.label.
 *
 * ai_results + raw_report['tb'] adalah legacy/read-only compatibility layer
 * (ditulis dual-write oleh job agar UI lama tetap hidup, bukan sumber utama).
 */
class AiRun extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const TERMINAL = [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED];

    protected $fillable = [
        'run_id',
        'study_id',
        'series_id',
        'task_id',
        'model_id',
        'model_version',
        'threshold',
        'status',
        'input_reference',
        'result',
        'metadata',
        'error_code',
        'error_message',
        'created_by',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'threshold' => 'float',
            'input_reference' => 'array',
            'result' => 'array',
            'metadata' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function study()
    {
        return $this->belongsTo(Study::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }

    public function markRunning(): bool
    {
        $this->status = self::STATUS_RUNNING;
        $this->started_at = now();

        return $this->save();
    }

    public function markCompleted(array $envelope): bool
    {
        $this->status = self::STATUS_COMPLETED;
        $this->result = $envelope['result'] ?? null;
        $this->metadata = $envelope['metadata'] ?? null;
        $this->model_id = $envelope['model']['id'] ?? $this->model_id;
        $this->model_version = $envelope['model']['version'] ?? $this->model_version;
        $this->threshold = $envelope['result']['classification']['threshold'] ?? $this->threshold;
        $this->completed_at = now();

        return $this->save();
    }

    public function markFailed(?string $code, string $message): bool
    {
        $this->status = self::STATUS_FAILED;
        $this->error_code = $code ? substr($code, 0, 64) : null;
        $this->error_message = substr($message, 0, 1000);
        $this->completed_at = now();

        return $this->save();
    }

    public function markCancelled(): bool
    {
        $this->status = self::STATUS_CANCELLED;
        $this->completed_at = now();

        return $this->save();
    }
}
