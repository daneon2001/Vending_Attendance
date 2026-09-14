<?php

namespace App\Services\FieldIdentity;

interface OtpProvider
{
    /** No persistence or logging of plaintext; result is local/testing only. */
    public function deliver(#[\SensitiveParameter] string $phone, #[\SensitiveParameter] string $code): string;
}
