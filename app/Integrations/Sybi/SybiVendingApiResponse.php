<?php

namespace App\Integrations\Sybi;

final readonly class SybiVendingApiResponse
{
    public function __construct(
        public int $httpStatus,
        public int $total,
        public array $data,
        public array $warnings = [],
    ) {}
}
