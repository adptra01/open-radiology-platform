<?php

namespace App\Enums;

enum Role: string
{
    case SuperAdmin = 'super-admin';
    case Admin = 'admin';
    case Radiographer = 'radiographer';
    case Radiologist = 'radiologist';
    case ReferringDoctor = 'referring-doctor';
    case Registration = 'registration';
    case Scheduler = 'scheduler';
    case PacsAdmin = 'pacs-admin';
    case Auditor = 'auditor';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Admin => 'Admin',
            self::Radiographer => 'Radiographer',
            self::Radiologist => 'Radiologist',
            self::ReferringDoctor => 'Referring Doctor',
            self::Registration => 'Registration',
            self::Scheduler => 'Scheduler',
            self::PacsAdmin => 'PACS Admin',
            self::Auditor => 'Auditor',
        };
    }
}