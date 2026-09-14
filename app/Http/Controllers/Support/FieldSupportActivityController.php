<?php

namespace App\Http\Controllers\Support;

use App\Services\Support\FieldSupportActivities;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FieldSupportActivityController
{
    public function __invoke(Request $request, string $action, FieldSupportActivities $activities): Response
    {
        try {
            // Native JSON transports file bytes transiently as base64; allow bounded encoding overhead.
            $max = 4 * (int) ceil((int) config('support.evidence.max_size_bytes') / 3) + 65536;
            abort_if(strlen($request->getContent()) > $max, 413);

            return response()->json(match ($action) {
                'capability' => $activities->capability(),
                'challenge' => $activities->challenge($request->all()),
                'execute' => $activities->execute($request->all()),
            });
        } catch (HttpResponseException $exception) {
            // Preserve the domain's safe geofence rejection, not the native middleware's generic 503.
            return $exception->getResponse();
        }
    }
}
