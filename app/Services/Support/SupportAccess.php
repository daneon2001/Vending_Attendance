<?php

namespace App\Services\Support;

use App\Enums\Vending\VendingCatalogSource;
use App\Models\Device;
use App\Models\SupportIntegration;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\VendingMachine;
use Illuminate\Database\Eloquent\Builder;

class SupportAccess
{
    public function reservedForFuturePhysicalTest(VendingMachine $machine): bool
    {
        return $machine->source === VendingCatalogSource::SYBI
            && in_array((string) $machine->machine_code, (array) config('support.reserved_sybi_identifiers', ['7']), true);
    }

    public function assertCapturedMachine(SupportActor $actor, VendingMachine $machine, ?string $expected): void
    {
        if ($actor->kind === 'device' && $expected !== null && strcasecmp($expected, $machine->uuid) !== 0) {
            $this->machineChanged();
        }
    }

    public function lockDeviceContext(SupportActor $actor, int $machineId): void
    {
        if ($actor->kind !== 'device') {
            return;
        }
        $device = Device::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
        abort_unless($device->isOperationalVendingDevice(), 403, 'El equipo ya no está habilitado.');
        if ((int) $device->vending_machine_id !== $machineId) {
            $this->machineChanged();
        }
    }

    private function machineChanged(): never
    {
        throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
            'code' => 'MACHINE_CHANGED',
            'message' => 'El reporte pertenece a la máquina anterior. Se conserva en este equipo; solicita ayuda de soporte.',
        ], 409));
    }

    public function authorize(SupportActor $actor, string $action, ?SupportTicket $ticket = null): void
    {
        if ($actor->kind === 'system') {
            return;
        }
        $allowed = false;
        if ($actor->kind === 'user' && $actor->model instanceof User && $actor->model->estatus) {
            $permission = match ($action) {
                'read', 'evidence.read', 'evidence.download' => 'view',
                'create' => 'report',
                'comment', 'evidence.create' => 'comment',
                'transition' => 'resolve',
                default => $action,
            };
            $allowed = $actor->model->hasPermission('support', $permission);
        } elseif ($actor->kind === 'device' && $actor->model instanceof Device) {
            $allowed = $actor->model->isOperationalVendingDevice()
                && in_array($action, ['read', 'create', 'comment', 'evidence.create', 'evidence.read', 'evidence.download', 'verify'], true);
        } elseif ($actor->kind === 'integration' && $actor->model instanceof SupportIntegration && $actor->model->active) {
            $scope = match ($action) {
                'read' => 'support.tickets.read', 'create' => 'support.tickets.create',
                'comment' => 'support.comments.create',
                'assign', 'transition', 'resolve', 'manage' => 'support.tickets.'.$action,
                'evidence.read', 'evidence.download' => 'support.'.$action,
                default => '',
            };
            $allowed = $scope !== '' && in_array($scope, $actor->abilities, true);
        }
        abort_unless($allowed, 403, 'No tienes permiso para esta acción de soporte.');
        if ($ticket !== null) {
            abort_unless($this->scope($actor, SupportTicket::query())->whereKey($ticket->id)->exists(), 404, 'Reporte no disponible.');
        }
    }

    public function scope(SupportActor $actor, Builder $query): Builder
    {
        return match ($actor->kind) {
            'system' => $query,
            'user' => $actor->model instanceof User && $actor->model->hasPermission('support', 'view_all')
                ? $query
                : $query->where('reporter_user_id', $actor->id),
            'device' => $query->where('device_id', $actor->id)->where('vending_machine_id', $actor->model?->vending_machine_id),
            'integration' => $query->whereIn('vending_machine_id', $actor->model->machines()->select('vending_machines.id')),
            default => $query->whereRaw('1 = 0'),
        };
    }

    public function ticket(SupportActor $actor, string $uuid): SupportTicket
    {
        return $this->scope($actor, SupportTicket::query())->where('uuid', $uuid)->firstOrFail();
    }

    public function machine(SupportActor $actor, int $machineId): VendingMachine
    {
        $query = VendingMachine::query();
        if ($actor->kind === 'device') {
            $query->whereKey($actor->model?->vending_machine_id);
        } elseif ($actor->kind === 'integration') {
            $query->whereIn('id', $actor->model->machines()->select('vending_machines.id'));
        } elseif (! in_array($actor->kind, ['user', 'system'], true)) {
            abort(403);
        }

        return $query->whereKey($machineId)->firstOrFail();
    }

    public function device(SupportActor $actor, int $deviceId): Device
    {
        $query = Device::query();
        if ($actor->kind === 'device') {
            $query->whereKey($actor->id);
        }
        $device = $query->whereKey($deviceId)->firstOrFail();
        $this->machine($actor, (int) $device->vending_machine_id);

        return $device;
    }
}
