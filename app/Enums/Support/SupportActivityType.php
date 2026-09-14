<?php

namespace App\Enums\Support;

enum SupportActivityType: string
{
    case INSTALLATION = 'INSTALLATION';
    case CONFIGURATION = 'CONFIGURATION';
    case MAINTENANCE = 'MAINTENANCE';
    case DIAGNOSTIC = 'DIAGNOSTIC';
    case REPAIR = 'REPAIR';
    case COMPONENT_REPLACEMENT = 'COMPONENT_REPLACEMENT';
    case CONNECTIVITY = 'CONNECTIVITY';
    case SOFTWARE_UPDATE = 'SOFTWARE_UPDATE';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::INSTALLATION => 'Instalación', self::CONFIGURATION => 'Configuración',
            self::MAINTENANCE => 'Mantenimiento', self::DIAGNOSTIC => 'Diagnóstico',
            self::REPAIR => 'Reparación', self::COMPONENT_REPLACEMENT => 'Cambio de componente',
            self::CONNECTIVITY => 'Conectividad', self::SOFTWARE_UPDATE => 'Actualización de software',
            self::OTHER => 'Otro',
        };
    }

    public function permission(): string
    {
        return match ($this) {
            self::MAINTENANCE, self::REPAIR, self::COMPONENT_REPLACEMENT => 'resolve',
            self::DIAGNOSTIC => 'verify',
            self::INSTALLATION, self::CONFIGURATION, self::CONNECTIVITY, self::SOFTWARE_UPDATE => 'configure',
            self::OTHER => 'manage',
        };
    }

    public function requiresMaintenance(): bool
    {
        return in_array($this, [self::MAINTENANCE, self::REPAIR, self::COMPONENT_REPLACEMENT], true);
    }
}
