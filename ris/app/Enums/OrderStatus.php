<?php

namespace App\Enums;

/**
 * Alur status Order (lihat Rencana §7):
 * REQUESTED → SCHEDULED → ARRIVED → IN_PROGRESS (MPPS N-CREATE)
 *          → ACQUIRED (MPPS N-SET COMPLETED) → COMPLETED (C-STORE match)
 * Terminal: CANCELLED / NO_SHOW / REJECTED
 */
enum OrderStatus: string
{
    case Requested = 'REQUESTED';
    case Scheduled = 'SCHEDULED';
    case Arrived = 'ARRIVED';
    case InProgress = 'IN_PROGRESS';
    case Acquired = 'ACQUIRED';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';
    case NoShow = 'NO_SHOW';
    case Rejected = 'REJECTED';

    /** Status yang masih "aktif" (belum terminal). */
    public function isActive(): bool
    {
        return in_array($this, [
            self::Requested,
            self::Scheduled,
            self::Arrived,
            self::InProgress,
            self::Acquired,
        ]);
    }

    /** Transisi yang diizinkan dari status ini. */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Requested => [self::Scheduled, self::Cancelled, self::Rejected],
            self::Scheduled => [self::Arrived, self::NoShow, self::Cancelled],
            self::Arrived => [self::InProgress, self::Cancelled],
            self::InProgress => [self::Acquired, self::Cancelled],
            self::Acquired => [self::Completed, self::Cancelled],
            self::Completed, self::Cancelled, self::NoShow, self::Rejected => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}