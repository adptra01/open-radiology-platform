<?php

namespace App\Enums;

/**
 * Status antrean transmisi ke PACS eksternal (M4):
 * PENDING → SENT (sukses) | FAILED (retry habis) | CANCELLED
 */
enum TransmissionStatus: string
{
    case Pending = 'PENDING';
    case Sending = 'SENDING';
    case Sent = 'SENT';
    case Failed = 'FAILED';
    case Cancelled = 'CANCELLED';
}