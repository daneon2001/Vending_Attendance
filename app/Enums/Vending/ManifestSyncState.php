<?php

namespace App\Enums\Vending;

enum ManifestSyncState: string
{
    case SYNCED = 'SYNCED';
    case PENDING = 'PENDING';
    case STALE = 'STALE';
    case ERROR = 'ERROR';
}
