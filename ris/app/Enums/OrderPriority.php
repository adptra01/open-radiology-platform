<?php

namespace App\Enums;

enum OrderPriority: string
{
    case Stat = 'STAT';
    case Urgent = 'URGENT';
    case Routine = 'ROUTINE';
}