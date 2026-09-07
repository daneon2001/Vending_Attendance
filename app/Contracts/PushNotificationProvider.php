<?php

namespace App\Contracts;

interface PushNotificationProvider
{
    /** @return array{status:string} Never claim delivery before a configured provider acknowledges it. */
    public function send(array $notification): array;
}
