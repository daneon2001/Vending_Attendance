<?php

namespace App\Services\Support;

use App\Enums\Support\SupportActivityType;

class SupportActivityPresencePolicy
{
    public const CURRENT = 'FIELD_PHYSICAL_V1';

    // Initial product policy, not an eternal property of the activity enum.
    // Future remote execution must introduce an explicit version, never a client flag.
    public function requiresPhysicalPresence(SupportActivityType $type, string $version = self::CURRENT): bool
    {
        abort_unless($version === self::CURRENT, 409, 'Política de presencia no disponible.');

        return match ($type) {
            SupportActivityType::INSTALLATION, SupportActivityType::CONFIGURATION,
            SupportActivityType::MAINTENANCE, SupportActivityType::DIAGNOSTIC,
            SupportActivityType::REPAIR, SupportActivityType::COMPONENT_REPLACEMENT,
            SupportActivityType::CONNECTIVITY, SupportActivityType::SOFTWARE_UPDATE,
            SupportActivityType::OTHER => true,
        };
    }
}
