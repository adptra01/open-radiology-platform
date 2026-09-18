<?php

namespace App\Models;

use App\Enums\ReportStatus;
use App\Models\AuditLog;
use App\Services\IdentifierService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Report extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'order_id',
        'study_id',
        'radiologist_id',
        'report_number',
        'status',
        'findings',
        'impression',
        'addendum',
        'dictated_at',
        'verified_at',
        'finalized_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReportStatus::class,
            'dictated_at' => 'datetime',
            'verified_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Report $report) {
            if (blank($report->report_number)) {
                $report->report_number = app(IdentifierService::class)->nextReportNumber();
            }
            if (blank($report->status)) {
                $report->status = ReportStatus::Draft;
            }
        });

        static::created(function (Report $report) {
            AuditLog::record('report.created', $report, ['order_id' => $report->order_id]);
        });
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function study()
    {
        return $this->belongsTo(Study::class);
    }

    public function radiologist()
    {
        return $this->belongsTo(User::class, 'radiologist_id');
    }

    /**
     * Transisi status yang aman (DRAFT → DICTATED → VERIFIED → FINAL).
     * Mengisi timestamp fase yang relevan.
     */
    public function transitionTo(ReportStatus $target): bool
    {
        if (! $this->status->canTransitionTo($target)) {
            return false;
        }

        $from = $this->status->value;
        $this->status = $target;
        match ($target) {
            ReportStatus::Dictated => $this->dictated_at = now(),
            ReportStatus::Verified => $this->verified_at = now(),
            ReportStatus::Final => $this->finalized_at = now(),
            default => null,
        };

        $saved = $this->save();
        if ($saved) {
            AuditLog::record('report.transitioned', $this, ['from' => $from, 'to' => $target->value]);
        }

        return $saved;
    }
}