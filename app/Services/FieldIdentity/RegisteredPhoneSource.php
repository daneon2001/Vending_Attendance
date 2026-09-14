<?php

namespace App\Services\FieldIdentity;

use App\Models\Employee;

/**
 * Integration seam, deliberately unavailable until a trusted corporate source
 * and its ownership/provenance are approved. Never read a request phone.
 */
class RegisteredPhoneSource
{
    public function forEmployee(Employee $employee): ?string
    {
        $user = auth()->user();
        if ($user instanceof \App\Models\User
            && app(EnrollmentIdentity::class)->isLocalDemo($user, $employee)) {
            return app(LocalDemoPhone::class)->value();
        }

        return null;
    }
}
