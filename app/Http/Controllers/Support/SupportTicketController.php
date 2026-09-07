<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\SupportTicketEvent;
use App\Services\Support\SupportAccess;
use App\Services\Support\SupportActor;
use App\Services\Support\SupportPresenter;
use App\Services\Support\SupportTicketService;
use Illuminate\Http\Request;

class SupportTicketController extends Controller
{
    public function __construct(private readonly SupportAccess $access, private readonly SupportPresenter $presenter, private readonly SupportTicketService $tickets) {}

    public function index(Request $request)
    {
        return response()->json(app(\App\Services\Support\SupportQueries::class)->listing(SupportActor::fromRequest($request), $request->query()));
    }

    public function show(Request $request, string $ticket)
    {
        return response()->json(app(\App\Services\Support\SupportQueries::class)->detail(SupportActor::fromRequest($request), $ticket));
    }

    public function changes(Request $request)
    {
        $actor = SupportActor::fromRequest($request);
        $this->access->authorize($actor, 'read');
        $data = $request->validate(['after_sequence' => 'sometimes|integer|min:0', 'limit' => 'sometimes|integer|min:1|max:100']);
        $after = (int) ($data['after_sequence'] ?? 0);
        $limit = (int) ($data['limit'] ?? 50);
        $ids = $this->access->scope($actor, SupportTicket::query())->select('id');
        $events = SupportTicketEvent::query()->whereIn('ticket_id', $ids)->where('public', true)->where('sequence', '>', $after)
            ->with('ticket.vendingMachine')->orderBy('sequence')->limit($limit + 1)->get();
        $more = $events->count() > $limit;
        $page = $events->take($limit);

        return response()->json(['events' => $page->map(fn ($event) => $this->presenter->event($event, $actor))->values(),
            'next_sequence' => (int) ($page->last()?->sequence ?? $after), 'has_more' => $more]);
    }

    public function store(Request $request)
    {
        return response()->json($this->tickets->create(SupportActor::fromRequest($request), $request->all()), 201);
    }

    public function comment(Request $request, string $ticket)
    {
        return response()->json($this->tickets->comment(SupportActor::fromRequest($request), $ticket, $request->all()));
    }

    public function assign(Request $request, string $ticket)
    {
        return response()->json($this->tickets->assign(SupportActor::fromRequest($request), $ticket, $request->all()));
    }

    public function transition(Request $request, string $ticket)
    {
        return response()->json($this->tickets->transition(SupportActor::fromRequest($request), $ticket, $request->all()));
    }

    public function context(Request $request)
    {
        $actor = SupportActor::fromRequest($request);
        $this->access->authorize($actor, 'read');
        abort_unless($actor->kind === 'device', 404);
        $device = $actor->model;
        $machine = $this->access->machine($actor, (int) $device->vending_machine_id);
        $geofence = $machine->activeGeofence()->first();

        return response()->json([
            'device' => ['uuid' => $device->uuid, 'name' => $device->device_name],
            'machine' => ['uuid' => $machine->uuid, 'code' => $machine->machine_code, 'name' => $machine->name],
            'categories' => collect(config('support.categories'))->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'evidence_policy' => collect(config('support.evidence'))->only(['max_size_bytes', 'max_count', 'allowed_mimes']),
            'geofence' => $geofence ? [
                'uuid' => $geofence->uuid, 'version' => $geofence->version, 'type' => 'CIRCLE',
                'latitude' => (float) $geofence->center_latitude, 'longitude' => (float) $geofence->center_longitude,
                'radius_m' => (float) $geofence->radius_m, 'tolerance_m' => (float) $geofence->tolerance_m,
                'minimum_acceptable_accuracy_m' => $geofence->minimum_acceptable_accuracy_m !== null ? (float) $geofence->minimum_acceptable_accuracy_m : null,
            ] : null,
            'server_time' => now('UTC')->toISOString(),
        ]);
    }
}
