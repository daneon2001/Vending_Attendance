<?php

namespace App\Http\Controllers;

use App\Services\ReadinessProbe;
use Illuminate\Http\JsonResponse;

final class ReadinessController
{
    public function __invoke(ReadinessProbe $probe): JsonResponse
    {
        $ready = $probe->ready();
        return response()->json(['status' => $ready ? 'ready' : 'not_ready'], $ready ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }
}
