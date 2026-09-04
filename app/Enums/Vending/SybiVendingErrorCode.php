<?php

namespace App\Enums\Vending;

enum SybiVendingErrorCode: string
{
    case AUTH_ERROR = 'AUTH_ERROR';
    case VALIDATION_ERROR = 'VALIDATION_ERROR';
    case SOURCE_ERROR = 'SOURCE_ERROR';
    case SOURCE_NOT_CONFIGURED = 'SOURCE_NOT_CONFIGURED';
    case NETWORK_ERROR = 'NETWORK_ERROR';
    case INVALID_RESPONSE = 'INVALID_RESPONSE';
    case SCHEMA_ERROR = 'SCHEMA_ERROR';
    case SYNC_ERROR = 'SYNC_ERROR';
}
