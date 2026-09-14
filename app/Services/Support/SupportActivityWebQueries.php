<?php

namespace App\Services\Support;

use App\Enums\Support\SupportActivityStatus;
use App\Enums\Support\SupportActivityType;
use App\Models\Employee;
use App\Models\EmployeeMachineAssignment;
use App\Models\User;
use App\Models\VendingMachine;
use App\Models\VendingSupportActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Read-only web projections. Execution and identity rules stay in the domain. */
class SupportActivityWebQueries
{
    public function __construct(private readonly SupportActivityAccess $access, private readonly SupportAccess $tickets) {}

    public function unavailableReason(): ?string
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->estatus, 403);
        $this->access->permission($user, 'view');
        if (! Schema::hasColumn('users', 'employee_id') || ! Schema::hasTable('vending_support_activities')) {
            return 'Las actividades de campo aún no están habilitadas en este entorno. Solicita la habilitación autorizada a administración.';
        }

        return User::authenticatedEmployee() === null && ! $this->demoObserver($user)
            ? 'Tu cuenta aún no tiene una identidad laboral habilitada para actividades de campo. Solicita ayuda a administración.' : null;
    }

    public function types(): array
    {
        return array_map(fn ($type) => ['value' => $type->value, 'label' => $type->label()], SupportActivityType::cases());
    }

    private function identity(): array
    {
        $observer = auth()->user();
        if ($observer instanceof User && $this->demoObserver($observer)) {
            return [$observer, null];
        }
        [$user, $employee] = $this->access->identity();
        $user->loadMissing('roles.permissions');

        return [$user, $employee];
    }

    public function listing(array $filters): array
    {
        [$user, $employee] = $this->identity();
        $query = $this->filtered($this->visible($user, $employee), $filters, $user);
        $page = $this->withRelations($query, $user)->orderByDesc('id')->paginate(25)->withQueryString();

        return ['activities' => $page->through(fn ($row) => $this->row($row)),
            'canCreate' => $employee !== null && $user->hasPermission('support', 'assign')];
    }

    private function filtered(Builder $query, array $filters, User $user): Builder
    {
        if ($search = trim($filters['search'] ?? '')) {
            $query->where(function (Builder $q) use ($search): void {
                $q->where('title', 'like', '%'.$search.'%')
                    ->orWhereHas('vendingMachine', fn ($m) => $m->where('machine_code', 'like', '%'.$search.'%')->orWhere('name', 'like', '%'.$search.'%'))
                    ->orWhereHas('employee', fn ($e) => $e->where('employee_number', 'like', '%'.$search.'%')->orWhere('full_name', 'like', '%'.$search.'%'));
                if (preg_match('/^(?:ACT-)?0*([1-9][0-9]{0,17})$/i', $search, $match)) {
                    $q->orWhere('id', $match[1]);
                }
            });
        }
        foreach (['status', 'activity_type', 'vending_machine_id', 'employee_id'] as $field) {
            if (filled($filters[$field] ?? null)) {
                $query->where($field, $filters[$field]);
            }
        }
        // Dates are operator calendar days in CDMX, translated to UTC range predicates.
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from'], 'America/Mexico_City')->startOfDay()->utc());
        }
        if (! empty($filters['to'])) {
            $query->where('created_at', '<', Carbon::parse($filters['to'], 'America/Mexico_City')->addDay()->startOfDay()->utc());
        }
        // The presence filter never discloses a ticket outside the independent ticket scope.
        $visibleTicketIds = $this->tickets->scope(SupportActor::user($user), \App\Models\SupportTicket::query())->select('id');
        if (($filters['has_ticket'] ?? '') === 'yes') {
            $query->whereIn('support_ticket_id', $visibleTicketIds);
        } elseif (($filters['has_ticket'] ?? '') === 'no') {
            $query->where(fn ($q) => $q->whereNull('support_ticket_id')->orWhereNotIn('support_ticket_id', $visibleTicketIds));
        }
        if (! empty($filters['ticket_uuid'])) {
            $ticket = $this->tickets->ticket(SupportActor::user($user), $filters['ticket_uuid']);
            $this->tickets->authorize(SupportActor::user($user), 'read', $ticket);
            $query->where('support_ticket_id', $ticket->id);
        }

        return $query;
    }

    private function withRelations(Builder $query, User $user): Builder
    {
        return $query->with([
            'vendingMachine:id,uuid,machine_code,name,address_line,status',
            'employee' => fn ($q) => $q->select('id', 'employee_number', 'full_name', 'status')
                ->withExists(['user as has_account', 'user as has_active_account' => fn ($u) => $u->where('estatus', true)]),
            'supportTicket' => fn ($q) => $this->tickets->scope(SupportActor::user($user), $q->getQuery())->select('id', 'uuid', 'created_at'),
        ]);
    }

    private function row(VendingSupportActivity $row): array
    {
        return [
            'uuid' => $row->uuid, 'folio' => 'ACT-'.str_pad((string) $row->id, 6, '0', STR_PAD_LEFT),
            'title' => $row->title, 'activity_type' => $row->activity_type->value,
            'activity_type_label' => $row->activity_type->label(), 'status' => $row->status->value,
            'created_at' => $row->created_at?->toISOString(), 'started_at' => $row->started_at?->toISOString(),
            'completed_at' => $row->completed_at?->toISOString(), 'cancelled_at' => $row->cancelled_at?->toISOString(),
            'geofence_result' => $row->geofence_result?->value ?? 'NOT_EVALUATED',
            'machine' => $row->vendingMachine?->only(['uuid', 'machine_code', 'name', 'address_line', 'status']),
            'employee' => $row->employee?->only(['employee_number', 'full_name', 'status', 'has_account', 'has_active_account']),
            'ticket' => $row->supportTicket?->only(['uuid', 'folio']),
        ];
    }

    public function detail(string $uuid): array
    {
        [$user, $employee] = $this->identity();
        $row = $this->withRelations($this->visible($user, $employee), $user)->where('uuid', $uuid)->firstOrFail();
        $canCancel = false;
        if ($employee !== null && in_array($row->status, [SupportActivityStatus::ASSIGNED, SupportActivityStatus::IN_PROGRESS], true)) {
            try {
                if ($user->hasPermission('support', 'assign')) {
                    $this->access->machine($employee, $row->vendingMachine);
                } else {
                    $this->access->execute($row, $user, $employee, $row->vendingMachine);
                }
                $canCancel = true;
            } catch (HttpException $exception) {
                if (! in_array($exception->getStatusCode(), [403, 404], true)) {
                    throw $exception;
                }
            }
        }
        $events = DB::table('vending_support_activity_events as event')
            ->join('users as actor', 'actor.id', '=', 'event.user_id')
            ->where('event.activity_id', $row->id)->orderBy('event.occurred_at')->orderBy('event.id')
            ->limit(100)->get(['event.id', 'event.kind', 'event.occurred_at', 'actor.name as actor'])
            ->map(fn ($event) => ['id' => $event->id, 'kind' => $event->kind, 'actor' => $event->actor,
                'occurred_at' => Carbon::parse($event->occurred_at, 'UTC')->toISOString()]);

        $contributions = app(SupportActivityContributions::class)->present($row);
        $events = $events->concat(collect($contributions['contribution_events'])->map(fn ($event) => [
            'id' => $event['id'], 'kind' => $event['kind'], 'actor' => $event['author'],
            'occurred_at' => $event['captured_at'], 'received_at' => $event['received_at'],
        ]))->sortBy('occurred_at')->values();

        return ['activity' => $this->row($row) + $contributions + [
            'description' => $row->description, 'cancellation_reason' => $row->cancellation_reason,
            'snapshot' => [
                'result' => $row->geofence_result?->value ?? 'NOT_EVALUATED',
                'distance_m' => $row->distance_m, 'accuracy_m' => $row->started_accuracy_m,
                'version' => $row->geofence_version, 'evaluated_at' => $row->geofence_evaluated_at?->toISOString(),
            ],
            'presence_policy' => $row->presence_policy,
        ], 'events' => $events, 'canCancel' => $canCancel];
    }

    public function options(array $input): array
    {
        [$user, $employee] = $this->identity();
        $visible = $this->visible($user, $employee);
        $create = ($input['purpose'] ?? 'filter') === 'create';
        abort_if($employee === null && ($create || $input['kind'] === 'tickets'), 403);
        if ($create) {
            $this->access->permission($user, 'assign');
        }
        $kind = $input['kind'];
        $search = trim($input['search'] ?? '');
        $machineId = $input['vending_machine_id'] ?? null;
        if ($kind === 'machines') {
            $query = VendingMachine::query()->select('id', 'machine_code', 'name', 'status', 'address_line');
            if ($create) {
                $query->where('status', 'ACTIVE')->whereIn('id', EmployeeMachineAssignment::query()->select('vending_machine_id')
                    ->where('employee_id', $employee->id)->active()->effectiveAt())
                    ->where(fn ($q) => $q->where('source', '!=', 'SYBI')->orWhereNull('source')
                        ->orWhereNotIn('machine_code', (array) config('support.reserved_sybi_identifiers', ['7'])));
            } else {
                $query->whereIn('id', $visible->select('vending_machine_id'));
            }
            $query->where(fn ($q) => $q->where('machine_code', 'like', '%'.$search.'%')->orWhere('name', 'like', '%'.$search.'%'));
        } elseif ($kind === 'employees') {
            $query = Employee::query()->select('id', 'employee_number', 'full_name')
                ->withExists(['user as has_account', 'user as has_active_account' => fn ($q) => $q->where('estatus', true)]);
            if ($create) {
                $machine = VendingMachine::query()->findOrFail($machineId);
                $this->access->machine($employee, $machine);
                $assignments = EmployeeMachineAssignment::query()->select('employee_id')->where('vending_machine_id', $machine->id)->active()->effectiveAt();
                if (SupportActivityType::from($input['activity_type'])->requiresMaintenance()) {
                    $assignments->maintenanceAllowed();
                }
                // Same eligibility as the domain; no conversion of corporate MANUAL imports.
                $query->activeForVending()->where('source', 'FORTIA')->whereNotNull('source_external_id')
                    ->whereRaw("TRIM(source_external_id) <> ''")->whereIn('id', $assignments);
            } else {
                $query->whereIn('id', $visible->select('employee_id'));
            }
            $query->where(fn ($q) => $q->where('employee_number', 'like', '%'.$search.'%')->orWhere('full_name', 'like', '%'.$search.'%'));
        } else {
            $machine = VendingMachine::query()->findOrFail($machineId);
            $this->access->machine($employee, $machine);
            $query = $this->tickets->scope(SupportActor::user($user), \App\Models\SupportTicket::query())
                ->where('vending_machine_id', $machine->id)->select('uuid', 'created_at', 'title', 'id');
            if (preg_match('/^INC-(\d{4})-(\d{1,18})$/i', $search, $folio)) {
                $query->whereKey((int) $folio[2])->whereYear('created_at', (int) $folio[1]);
            } else {
                $query->where('title', 'like', '%'.$search.'%');
            }
        }
        $page = $query->orderBy('id')->simplePaginate(20, ['*'], 'page', $input['page'] ?? 1);

        return ['data' => collect($page->items())->map(fn ($row) => $row->only(match ($kind) {
            'tickets' => ['uuid', 'folio', 'title'],
            'employees' => ['id', 'employee_number', 'full_name', 'has_account', 'has_active_account'],
            default => ['id', 'machine_code', 'name', 'status', 'address_line'],
        })),
            'page' => $page->currentPage(), 'has_more' => $page->hasMorePages()];
    }

    public function summary(array $filters): array
    {
        // Optional embedded section must not grant access or break pre-migration screens.
        $user = auth()->user();
        if (! $user instanceof User || ! $user->hasPermission('support', 'view') || $this->unavailableReason() !== null) {
            return ['available' => false];
        }
        [$user, $employee] = $this->identity();
        $query = $this->filtered($this->visible($user, $employee), $filters, $user);
        $rows = $this->withRelations($query, $user)->orderByDesc('id')->limit(5)->get();

        return ['available' => true, 'activities' => $rows->map(fn ($row) => $this->row($row))];
    }

    /** Read-only local DEMO observer, not an execution or global ticket grant. */
    private function demoObserver(User $user): bool
    {
        return app()->environment(['local', 'testing']) && $user->estatus && (int) $user->id === 2
            && $user->email === 'pilot.admin@example.test'
            && $user->hasPermission('support', 'view') && $user->hasPermission('employee_device', 'view');
    }

    private function visible(User $user, ?Employee $employee): Builder
    {
        if ($employee !== null) {
            return $this->access->visible($user, $employee);
        }
        abort_unless($this->demoObserver($user), 403);

        return VendingSupportActivity::query()->where('employee_id', 5)
            ->whereHas('employee', fn ($q) => $q->where('employee_number', '990001005')->where('source', 'DEMO'))
            ->whereHas('vendingMachine', fn ($q) => $q->where('machine_code', 'VM-DEMO-001')->where('source', 'DEMO'))
            ->whereIn('activity_type', ['MAINTENANCE', 'REPAIR', 'COMPONENT_REPLACEMENT']);
    }

    /** Notification projection/read scope; never switches the authenticated principal. */
    public function notificationScope(User $user): Builder
    {
        $empty = VendingSupportActivity::query()->whereRaw('1 = 0');
        if (! $user->estatus || ! $user->hasPermission('support', 'view')) {
            return $empty;
        }
        if ($this->demoObserver($user)) {
            return $this->visible($user, null);
        }
        $employee = $user->employee()->activeForVending()->where('source', 'FORTIA')->first();

        return $employee && trim((string) $employee->source_external_id) !== ''
            ? $this->visible($user, $employee) : $empty;
    }
}
