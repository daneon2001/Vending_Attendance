<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\GeofenceValidateRequest;
use App\Models\VendingMachine;
use App\Services\Vending\GeofenceValidationService;
use Illuminate\Http\JsonResponse;

class GeofenceValidationController extends Controller
{
    public function __invoke(GeofenceValidateRequest $request, GeofenceValidationService $service): JsonResponse
    {
        $data = $request->validated();
        $machine = VendingMachine::query()->where('uuid', $data['machine_uuid'])->firstOrFail();
        $capturedAt = $data['captured_at'] ?? now();
        $geofence = $machine->geofences()->effectiveAt($capturedAt)->first();

        if (! $geofence) {
            return response()->json([
                'message' => 'No existe una geocerca ACTIVE vigente para la máquina solicitada.',
                'code' => 'ACTIVE_GEOFENCE_NOT_FOUND',
            ], 422);
        }

        $result = $service->validate(
            $geofence,
            (float) $data['latitude'],
            (float) $data['longitude'],
            isset($data['accuracy']) ? (float) $data['accuracy'] : null,
            $capturedAt,
        );

        return response()->json(array_merge([
            'machine_id' => $machine->uuid,
            'geofence_version' => (int) $geofence->version,
        ], $result));
    }
}
