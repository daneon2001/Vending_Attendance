<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Http\Requests\Support\SupportActivityBrowseRequest;
use App\Services\Support\SupportActivityAccess;
use App\Services\Support\SupportActivityWebQueries;
use Inertia\Inertia;

class SupportActivityWebController extends Controller
{
    public function __construct(private readonly SupportActivityWebQueries $queries) {}

    public function index(SupportActivityBrowseRequest $request)
    {
        $unavailable = $this->queries->unavailableReason();

        return Inertia::render('Support/Activities/Index', [
            ...($unavailable === null ? $this->queries->listing($request->validated()) : ['activities' => null, 'canCreate' => false]),
            'filters' => $request->validated(), 'types' => $this->queries->types(), 'unavailable' => $unavailable,
        ]);
    }

    public function create()
    {
        $reason = $this->queries->unavailableReason();
        abort_if($reason !== null, 403, $reason ?? 'Acceso no disponible.');
        [$user] = app(SupportActivityAccess::class)->identity();
        app(SupportActivityAccess::class)->permission($user, 'assign');

        return Inertia::render('Support/Activities/Create', ['types' => $this->queries->types()]);
    }

    public function show(string $activity)
    {
        return Inertia::render('Support/Activities/Show', $this->queries->detail($activity));
    }

    public function options(SupportActivityBrowseRequest $request)
    {
        $reason = $this->queries->unavailableReason();
        abort_if($reason !== null, 403, $reason ?? 'Acceso no disponible.');

        return response()->json($this->queries->options($request->validated()))->header('Cache-Control', 'private, no-store');
    }

    public function summary(SupportActivityBrowseRequest $request)
    {
        return response()->json($this->queries->summary($request->validated()))->header('Cache-Control', 'private, no-store');
    }
}
