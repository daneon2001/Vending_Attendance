<?php

namespace App\Enums\Vending;

enum AttendanceManifestEvidenceStatus: string
{
    case CURRENT = 'CURRENT';
    case STALE = 'STALE';
    case UNKNOWN = 'UNKNOWN';
}
