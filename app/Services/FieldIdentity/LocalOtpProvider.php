<?php

namespace App\Services\FieldIdentity;

final class LocalOtpProvider implements OtpProvider
{
    public function deliver(#[\SensitiveParameter] string $phone, #[\SensitiveParameter] string $code): string
    {
        abort_unless(\App\Support\InternalBeta::simulationAllowed(), 503, 'Verificación no disponible.');

        // Simulation, NOT proof of real SMS possession. Never log or persist.
        return $code;
    }
}
