<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UnitDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'unit' => UnitResource::make($this)->resolve(),
            'clocks' => $this->clocks->map(function ($clock) {
                return [
                    'id' => $clock->id,
                    'clock_name' => $clock->clock_name,
                    'ip_address' => $clock->ip_address,
                    'monitoring_status' => $clock->monitoring_status,
                    'status' => (int) $clock->status,
                    'last_heartbeat_at' => optional($clock->last_heartbeat_at)?->toIso8601String(),
                ];
            }),
        ];
    }
}
