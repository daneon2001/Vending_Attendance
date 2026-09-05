<?php

namespace App\Http\Controllers\Vending;

use App\Enums\Vending\MobilePlatform;
use App\Enums\Vending\MobileReleaseChannel;
use App\Enums\Vending\MobileReleaseStatus;
use App\Enums\Vending\MobileReleaseTargetType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Vending\StoreMobileReleaseRequest;
use App\Http\Requests\Vending\StoreMobileReleaseTargetRequest;
use App\Http\Requests\Vending\UpdateMobileReleasePolicyRequest;
use App\Models\MobileRelease;
use App\Models\MobileReleasePolicy;
use App\Services\Vending\MobileReleaseManagementService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class MobileReleaseController extends Controller
{
    public function index(\Illuminate\Http\Request $request): Response
    {
        return Inertia::render('VendingFleet/Releases', [
            'releases' => MobileRelease::query()->with('targets')->latest('released_at')->latest('id')->get(),
            'policies' => MobileReleasePolicy::query()
                ->with(['currentRelease', 'recommendedRelease', 'minimumRelease'])
                ->orderBy('platform')->orderBy('channel')->get(),
            'platforms' => MobilePlatform::values(),
            'channels' => MobileReleaseChannel::values(),
            'statuses' => MobileReleaseStatus::values(),
            'targetTypes' => MobileReleaseTargetType::values(),
            'rolloutPercentages' => [0, 1, 5, 25, 100],
            'canManage' => $request->user()?->hasPermission('vending_machines', 'manage')
                || $request->user()?->hasPermission('settings', 'manage'),
        ]);
    }

    public function store(StoreMobileReleaseRequest $request, MobileReleaseManagementService $service): RedirectResponse
    {
        $service->create($request->validated(), $request->user());

        return back()->with('success', 'Metadata de release registrada. No se almacenó ningún binario.');
    }

    public function updatePolicy(UpdateMobileReleasePolicyRequest $request, MobileReleaseManagementService $service): RedirectResponse
    {
        $service->updatePolicy($request->validated(), $request->user());

        return back()->with('success', 'Política de versión actualizada.');
    }

    public function addTarget(
        StoreMobileReleaseTargetRequest $request,
        MobileRelease $mobileRelease,
        MobileReleaseManagementService $service,
    ): RedirectResponse {
        $service->addTarget($mobileRelease, $request->validated());

        return back()->with('success', 'Target de rollout agregado.');
    }

    public function block(
        \Illuminate\Http\Request $request,
        MobileRelease $mobileRelease,
        MobileReleaseManagementService $service,
    ): RedirectResponse {
        $service->block($mobileRelease, $request->user());

        return back()->with('success', 'Release bloqueada; el rollout quedó en 0%.');
    }
}
