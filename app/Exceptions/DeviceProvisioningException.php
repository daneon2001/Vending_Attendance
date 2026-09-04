<?php

namespace App\Exceptions;

use RuntimeException;

class DeviceProvisioningException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 401,
    ) {
        parent::__construct($message);
    }
}
