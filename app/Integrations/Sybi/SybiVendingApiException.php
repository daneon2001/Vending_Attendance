<?php

namespace App\Integrations\Sybi;

use App\Enums\Vending\SybiVendingErrorCode;
use RuntimeException;

class SybiVendingApiException extends RuntimeException
{
    public function __construct(
        public readonly SybiVendingErrorCode $errorCode,
        string $safeMessage,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($safeMessage);
    }
}
