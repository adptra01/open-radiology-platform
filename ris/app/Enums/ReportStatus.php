<?php

namespace App\Enums;

/**
 * Alur status Report (fase lanjut setelah COMPLETED, lihat Rencana §7):
 * DRAFT → DICTATED → VERIFIED → FINAL
 *
 * - DRAFT: diketik radiolog, belum resmi.
 * - DICTATED: diketik/dictation, menunggu verifikasi radiolog senior.
 * - VERIFIED: diverifikasi dan ditandatangani oleh radiolog.
 * - FINAL: final, tidak bisa diubah lagi (koreksi lewat addendum).
 *
 * Terminal: FINAL. CANCELLED untuk report yang dibatalkan.
 */
enum ReportStatus: string
{
    case Draft = 'DRAFT';
    case Dictated = 'DICTATED';
    case Verified = 'VERIFIED';
    case Final = 'FINAL';
    case Cancelled = 'CANCELLED';

    /** Status yang masih bisa diedit. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Dictated], true);
    }

    /** Transisi yang diizinkan dari status ini. */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Dictated, self::Cancelled],
            self::Dictated => [self::Verified, self::Cancelled],
            self::Verified => [self::Final, self::Cancelled],
            self::Final, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}