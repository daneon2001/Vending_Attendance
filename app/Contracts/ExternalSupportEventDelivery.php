<?php

namespace App\Contracts;

interface ExternalSupportEventDelivery
{
    /** @return array{status:string} Event UUID remains stable across eventual transport retries. */
    public function deliver(array $event): array;
}
