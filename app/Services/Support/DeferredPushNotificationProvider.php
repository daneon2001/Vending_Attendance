<?php

namespace App\Services\Support;

use App\Contracts\PushNotificationProvider;

class DeferredPushNotificationProvider implements PushNotificationProvider
{
    public function send(array $notification): array
    {
        return ['status' => 'DEFERRED_CONFIGURATION'];
    }
}
