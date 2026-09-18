<?php

namespace App\Enums;

enum AppointmentStatus: string
{
    case Scheduled = 'SCHEDULED';
    case Confirmed = 'CONFIRMED';
    case CheckedIn = 'CHECKED_IN';
    case Completed = 'COMPLETED';
    case NoShow = 'NO_SHOW';
    case Cancelled = 'CANCELLED';
}