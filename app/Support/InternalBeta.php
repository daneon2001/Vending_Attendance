<?php

namespace App\Support;

final class InternalBeta
{
    public static function enabled(): bool
    {
        return app()->environment('beta')
            && config('internal_beta.enabled') === true
            && config('app.debug') === false;
    }

    public static function simulationAllowed(): bool
    {
        // Preserve isolated tests and local DEMO; never enable production by flag alone.
        return app()->environment(['local', 'testing']) || self::enabled();
    }
}
