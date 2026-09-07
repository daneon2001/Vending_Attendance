<?php

namespace App\Services\Support;

use App\Models\Device;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\VendingMachine;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SupportQueries
{
    public function __construct(private readonly SupportAccess $access, private readonly SupportPresenter $presenter) {}

    public function listing(SupportActor $actor, array $input): array
    {
        $this->access->authorize($actor, 'read');
        $filters = Validator::make($input, [
            'page' => 'sometimes|integer|min:1', 'limit' => 'sometimes|integer|min:1|max:100',
            'search' => 'nullable|string|max:160', 'status' => ['nullable', Rule::in(['OPEN', 'IN_PROGRESS', 'WAITING', 'RESOLVED', 'CLOSED', 'CANCELLED'])],
            'severity' => ['nullable', Rule::in(['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'])],
            'assignee_id' => 'nullable|integer|min:1', 'vending_machine_id' => 'nullable|integer|min:1',
            'device_id' => 'nullable|integer|min:1', 'category' => 'nullable|string|max:64',
            'source' => 'nullable|string|max:32', 'from' => 'nullable|date', 'to' => 'nullable|date',
        ])->validate();
        $query = $this->access->scope($actor, SupportTicket::query())->with(['vendingMachine', 'device', 'assignee', 'integration']);
        foreach (['status', 'severity', 'assignee_id', 'vending_machine_id', 'device_id', 'category', 'source'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $query->where($field, $filters[$field]);
            }
        }
        if (! empty($filters['search'])) {
            if (preg_match('/^INC-(\d{4})-(\d{1,18})$/i', $filters['search'], $folio)) {
                $query->whereKey((int) $folio[2])->whereYear('created_at', (int) $folio[1]);
            } else {
                $query->where('title', 'like', '%'.addcslashes($filters['search'], '%_\\').'%');
            }
        }
        if (! empty($filters['from'])) {
            $query->where('reported_at', '>=', \Illuminate\Support\Carbon::parse($filters['from'], 'America/Mexico_City')->startOfDay()->utc());
        }
        if (! empty($filters['to'])) {
            $query->where('reported_at', '<', \Illuminate\Support\Carbon::parse($filters['to'], 'America/Mexico_City')->startOfDay()->addDay()->utc());
        }
        $page = $query->orderByDesc('id')->paginate($filters['limit'] ?? 50, ['*'], 'page', $filters['page'] ?? 1);

        return [
            'tickets' => $page->getCollection()->map(fn ($ticket) => $this->presenter->ticket($ticket))->all(),
            'pagination' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()],
        ];
    }

    public function detail(SupportActor $actor, string $uuid): array
    {
        $model = $this->access->ticket($actor, $uuid);
        $this->access->authorize($actor, 'read', $model);
        $events = $model->events()->where('public', true)->with('ticket.vendingMachine')->orderByDesc('sequence')->limit(100)->get()->reverse()->values();
        $evidence = [];
        try {
            $this->access->authorize($actor, 'evidence.read', $model);
            $evidence = $model->evidence()->where('status', 'CONFIRMED')->get()
                ->map(fn ($item) => app(SupportEvidenceService::class)->metadata($item))->all();
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            if ($exception->getStatusCode() !== 403) {
                throw $exception;
            }
        }

        return ['ticket' => $this->presenter->ticket($model), 'allowedTransitions' => app(SupportTicketService::class)->allowedTransitions($actor, $model),
            'events' => $events->map(fn ($event) => $this->presenter->event($event, $actor))->all(), 'evidence' => $evidence];
    }

    public function stats(SupportActor $actor): array
    {
        $this->access->authorize($actor, 'read');
        $base = $this->access->scope($actor, SupportTicket::query());
        $open = (clone $base)->whereIn('status', ['OPEN', 'IN_PROGRESS', 'WAITING']);
        $today = now('America/Mexico_City')->startOfDay()->utc();

        return [
            'open' => (clone $open)->count(),
            'high_critical' => (clone $open)->whereIn('severity', ['HIGH', 'CRITICAL'])->count(),
            'unassigned' => (clone $open)->whereNull('assignee_id')->count(),
            'sla_warning' => (clone $open)->where(fn ($q) => $q->where('response_warned', true)->orWhere('resolution_warned', true))
                ->where('response_breached', false)->where('resolution_breached', false)->count(),
            'sla_breached' => (clone $open)->where(fn ($q) => $q->where('response_breached', true)->orWhere('resolution_breached', true))->count(),
            'created_today' => (clone $base)->where('created_at', '>=', $today)->where('created_at', '<', $today->copy()->addDay())->count(),
        ];
    }

    public function options(SupportActor $actor, string $search = ''): array
    {
        $this->access->authorize($actor, 'read');
        $machines = VendingMachine::query()->select(['id', 'uuid', 'machine_code', 'name']);
        if ($actor->kind === 'device') {
            $machines->whereKey($actor->model->vending_machine_id);
        }
        if ($actor->kind === 'integration') {
            $machines->whereIn('id', $actor->model->machines()->select('vending_machines.id'));
        }
        if ($search !== '') {
            $machines->where(fn ($q) => $q->where('machine_code', 'like', '%'.addcslashes($search, '%_\\').'%')->orWhere('name', 'like', '%'.addcslashes($search, '%_\\').'%'));
        }
        $list = $machines->orderBy('machine_code')->limit(30)->get();
        $users = User::query()->where('estatus', true)->whereHas('roles.permissions', fn ($q) => $q->where('module', 'support')->whereIn('action', ['resolve', 'manage']))
            ->orderBy('name')->limit(100)->get(['id', 'name']);
        $devices = Device::query()->whereIn('vending_machine_id', $list->pluck('id'))->orderBy('id')->limit(60)->get(['id', 'uuid', 'device_name', 'vending_machine_id']);

        return ['machines' => $list->toArray(), 'assignees' => $users->toArray(), 'devices' => $devices->toArray(),
            'categories' => collect(config('support.categories'))->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all()];
    }
}
