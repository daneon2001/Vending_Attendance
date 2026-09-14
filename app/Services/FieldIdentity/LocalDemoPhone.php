<?php

namespace App\Services\FieldIdentity;

use Dotenv\Dotenv;

/** Reads just one ignored local setting. Testing NEVER reads a developer file. */
class LocalDemoPhone
{
    public function value(): ?string
    {
        if (! app()->environment('local')) {
            return null;
        }
        try {
            $path = base_path('.env.local');
            $values = is_file($path) ? Dotenv::parse(file_get_contents($path)) : [];
            $value = $values['DEVICE_DEMO_PHONE'] ?? null;

            return is_string($value) && preg_match('/^\\+52[0-9]{10}$/D', $value) === 1 ? $value : null;
        } catch (\Throwable) {
            // Parser errors may contain source lines: never report/log the exception.
            return null;
        }
    }
}
