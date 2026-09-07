<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\SupportVerification;
use App\Services\Support\SupportAccess;
use App\Services\Support\SupportActor;
use App\Services\Support\SupportQueries;
use App\Services\Support\SupportTicketService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SupportWebController extends Controller
{
    public function __construct(private readonly SupportQueries $queries, private readonly SupportTicketService $tickets, private readonly SupportAccess $access) {}

    public function index(Request $request)
    {
        $actor = SupportActor::fromRequest($request);

        return Inertia::render('Support/Index', [
            ...$this->queries->listing($actor, $request->query()), 'filters' => $request->only(['search', 'status', 'severity', 'assignee_id', 'vending_machine_id', 'device_id', 'category', 'source', 'from', 'to']),
            'stats' => $this->queries->stats($actor), 'options' => $this->queries->options($actor),
        ]);
    }

    public function show(Request $request, string $ticket)
    {
        $actor = SupportActor::fromRequest($request);

        return Inertia::render('Support/Show', [...$this->queries->detail($actor, $ticket), 'options' => $this->queries->options($actor), 'evidencePolicy' => collect(config('support.evidence'))->only(['max_size_bytes', 'max_count', 'allowed_mimes'])]);
    }

    public function options(Request $request)
    {
        $input = $request->validate(['search' => 'nullable|string|max:160']);

        return response()->json($this->queries->options(SupportActor::fromRequest($request), $input['search'] ?? ''));
    }

    public function summary(Request $request)
    {
        return response()->json($this->queries->stats(SupportActor::fromRequest($request)))
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request)
    {
        $result = $this->tickets->create(SupportActor::fromRequest($request), $request->all());

        return to_route('support.tickets.show', $result['ticket']['uuid'], 303)->with('success', 'Reporte enviado.');
    }

    public function comment(Request $request, string $ticket)
    {
        return $this->change($request, $ticket, 'comment');
    }

    public function assign(Request $request, string $ticket)
    {
        return $this->change($request, $ticket, 'assign');
    }

    public function transition(Request $request, string $ticket)
    {
        return $this->change($request, $ticket, 'transition');
    }

    private function change(Request $request, string $ticket, string $action)
    {
        try {
            $this->tickets->$action(SupportActor::fromRequest($request), $ticket, $request->all());
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }
            throw ValidationException::withMessages(['support' => $exception->getMessage()]);
        }

        return back(303)->with('success', 'Cambio guardado.');
    }

    public function verifications(Request $request)
    {
        $actor = SupportActor::fromRequest($request);
        $this->access->authorize($actor, 'read');
        $query = SupportVerification::query()->with(['device:id,uuid,device_name', 'vendingMachine:id,uuid,machine_code,name']);
        if (! $actor->model->hasPermission('support', 'view_all')) {
            $query->where('actor_kind', 'user')->where('actor_id', $actor->id);
        }
        $page = $query->orderByDesc('id')->paginate(30);

        return Inertia::render('Support/Verifications', ['verifications' => $page->through(fn ($v) => [
            'uuid' => $v->uuid, 'device' => $v->device, 'machine' => $v->vendingMachine,
            'summary' => $v->summary, 'checks' => $v->checks, 'completed_at' => $v->completed_at->toISOString(),
        ])]);
    }
}
