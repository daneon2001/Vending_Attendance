<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClockResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'clock_name' => $this->clock_name,
            'serial_number' => $this->serial_number,
            'firmware_version' => $this->firmware_version,
            'ip_address' => $this->ip_address,
            'type_inout' => $this->type_inout,
            'status' => $this->status,
            'status_label' => $this->status ? 'Activo' : 'Inactivo',
            'monitoring_status' => $this->monitoring_status ?? 'offline',
            'monitoring_message' => $this->last_status_message,
            'is_online' => $this->is_online,
            'last_heartbeat_at' => optional($this->last_heartbeat_at)?->toIso8601String(),
            'company' => [
                'id' => $this->company?->id,
                'name' => $this->company?->name,
            ],
            'location' => [
                'id' => $this->location?->id,
                'name' => $this->location?->name,
                'code' => $this->location?->code,
            ],
        ];
    }
}
