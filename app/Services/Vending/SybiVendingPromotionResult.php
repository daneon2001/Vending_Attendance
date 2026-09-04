<?php

namespace App\Services\Vending;

use App\Models\VendingMachine;

final readonly class SybiVendingPromotionResult
{
    public function __construct(
        public string $action,
        public ?VendingMachine $machine = null,
        public ?string $reason = null,
    ) {}
}
