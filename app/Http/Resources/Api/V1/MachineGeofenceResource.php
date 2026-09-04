<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MachineGeofenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'version' => (int) $this->version,
            'shape' => $this->shape?->value ?? $this->shape,
            'center_latitude' => (float) $this->center_latitude,
            'center_longitude' => (float) $this->center_longitude,
            'radius_m' => (int) $this->radius_m,
            'minimum_acceptable_accuracy_m' => $this->minimum_acceptable_accuracy_m,
            'tolerance_m' => (float) $this->tolerance_m,
            'valid_from' => $this->valid_from?->toIso8601String(),
            'valid_until' => $this->valid_until?->toIso8601String(),
            'status' => $this->status?->value ?? $this->status,
            'source' => $this->source,
        ];
    }
}
