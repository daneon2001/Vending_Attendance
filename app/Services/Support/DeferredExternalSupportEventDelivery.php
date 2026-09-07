<?php

namespace App\Services\Support;

use App\Contracts\ExternalSupportEventDelivery;

class DeferredExternalSupportEventDelivery implements ExternalSupportEventDelivery
{
    public function deliver(array $event): array
    {
        return ['status' => 'DEFERRED_CONFIGURATION'];
    }
}
