<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VendingMachineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'sybi_id' => $this->sybi_id,
            'machine_code' => $this->machine_code,
            'operational_code' => $this->operational_code,
            'name' => $this->name,
            'status' => $this->status?->value ?? $this->status,
            'address' => [
                'line' => $this->address_line,
                'neighborhood' => $this->neighborhood,
                'locality' => $this->locality,
                'municipality' => $this->municipality,
                'state' => $this->state,
                'postal_code' => $this->postal_code,
                'country' => $this->country,
            ],
            'coordinates' => [
                'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
                'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
                'source' => $this->coordinate_source?->value ?? $this->coordinate_source,
                'verified' => (bool) $this->coordinates_verified,
                'verified_at' => $this->coordinates_verified_at?->toIso8601String(),
            ],
            'timezone' => $this->timezone,
            'config_version' => (int) $this->config_version,
            'active_geofence' => new MachineGeofenceResource($this->whenLoaded('activeGeofence')),
        ];
    }
}
