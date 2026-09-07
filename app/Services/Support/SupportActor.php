<?php

namespace App\Services\Support;

use App\Models\Device;
use App\Models\SupportIntegration;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final readonly class SupportActor
{
    public function __construct(
        public string $kind,
        public int $id,
        public ?Model $model = null,
        public array $abilities = [],
    ) {}

    public static function user(User $user): self
    {
        return new self('user', $user->id, $user);
    }

    public static function device(Device $device): self
    {
        return new self('device', $device->id, $device);
    }

    public static function integration(SupportIntegration $integration, array $abilities): self
    {
        return new self('integration', $integration->id, $integration, $abilities);
    }

    public static function system(): self
    {
        return new self('system', 0);
    }

    public function key(): string
    {
        return $this->kind.':'.$this->id;
    }

    public static function fromRequest(Request $request): self
    {
        $actor = $request->attributes->get('support_actor');
        abort_unless($actor instanceof self, 401, 'Autenticación de soporte requerida.');

        return $actor;
    }
}
