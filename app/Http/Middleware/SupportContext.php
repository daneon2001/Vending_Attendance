<?php

namespace App\Http\Middleware;

use App\Models\Device;
use App\Models\User;
use App\Services\Support\SupportActor;
use Closure;
use Illuminate\Http\Request;

class SupportContext
{
    public function handle(Request $request, Closure $next, string $kind)
    {
        if ($kind === 'device') {
            $device = $request->attributes->get('vending_device');
            abort_unless($device instanceof Device && $device->isOperationalVendingDevice(), 401, 'Equipo no autorizado.');
            $actor = SupportActor::device($device);
        } else {
            $user = $request->user();
            abort_unless($user instanceof User && $user->estatus, 403, 'Usuario no habilitado.');
            $user->loadMissing('roles.permissions');
            $actor = SupportActor::user($user);
        }
        $request->attributes->set('support_actor', $actor);

        return $next($request);
    }
}
