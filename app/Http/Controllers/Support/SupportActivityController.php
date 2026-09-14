<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Http\Requests\Support\SupportActivityBrowseRequest;
use App\Http\Requests\Support\SupportActivityRequest;
use App\Models\VendingSupportActivity;
use App\Services\Support\SupportActivityAccess;
use App\Services\Support\SupportActivityService;

class SupportActivityController extends Controller
{
    public function __construct(private readonly SupportActivityAccess $access, private readonly SupportActivityService $activities) {}

    public function index(SupportActivityBrowseRequest $request)
    {
        if (! $request->expectsJson()) {
            return app(SupportActivityWebController::class)->index($request);
        }
        [$user, $employee] = $this->access->identity();
        $page = $this->access->visible($user, $employee)->orderByDesc('id')->paginate(25);
        $page->through(fn (VendingSupportActivity $activity) => $this->present($activity));

        return response()->json($page);
    }

    public function show(string $activity)
    {
        if (! request()->expectsJson()) {
            return app(SupportActivityWebController::class)->show($activity);
        }
        [$user, $employee] = $this->access->identity();
        $row = $this->access->visible($user, $employee)->where('uuid', $activity)->firstOrFail();

        return response()->json(['activity' => $this->present($row)]);
    }

    public function store(SupportActivityRequest $request)
    {
        try {
            $activity = $this->activities->create($request->validated());
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            if ($request->expectsJson() || $exception->getStatusCode() !== 409) {
                throw $exception;
            }
            throw \Illuminate\Validation\ValidationException::withMessages(['activity' => $exception->getMessage()]);
        }

        return $request->expectsJson()
            ? response()->json(['activity' => $this->present($activity)], 201)
            : to_route('support.activities.show', $activity->uuid, 303)->with('success', 'Actividad creada y asignada.');
    }

    public function start(SupportActivityRequest $request, string $activity)
    {
        return response()->json(['activity' => $this->present($this->activities->start($activity, $request->validated()))]);
    }

    public function complete(SupportActivityRequest $request, string $activity)
    {
        return response()->json(['activity' => $this->present($this->activities->complete($activity))]);
    }

    public function cancel(SupportActivityRequest $request, string $activity)
    {
        try {
            $row = $this->activities->cancel($activity, $request->validated('cancellation_reason'));
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            if ($request->expectsJson() || $exception->getStatusCode() !== 409) {
                throw $exception;
            }
            throw \Illuminate\Validation\ValidationException::withMessages(['activity' => $exception->getMessage()]);
        }

        return $request->expectsJson()
            ? response()->json(['activity' => $this->present($row)])
            : to_route('support.activities.show', $row->uuid, 303)->with('success', 'Actividad cancelada. Su historial se conserva.');
    }

    private function present(VendingSupportActivity $activity): array
    {
        // Deliberate allow-list: no precise GPS, arbitrary relationships or account attributes.
        return $activity->only([
            'uuid', 'activity_type', 'status', 'title', 'description', 'scheduled_at',
            'started_at', 'completed_at', 'cancelled_at', 'cancellation_reason',
            'requires_physical_presence', 'geofence_result',
        ]) + ['activity_type_label' => $activity->activity_type->label()];
    }
}
