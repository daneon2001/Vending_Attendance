<?php

namespace App\Http\Resources;

use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Schema;

class UnitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $lastHeartbeatAt = isset($this->last_heartbeat_at) && $this->last_heartbeat_at
            ? Carbon::parse($this->last_heartbeat_at)->setTimezone('America/Mexico_City')
            : null;
        $createdAt = $this->created_at ? Carbon::parse($this->created_at)->setTimezone('America/Mexico_City') : null;
        $updatedAt = $this->updated_at ? Carbon::parse($this->updated_at)->setTimezone('America/Mexico_City') : null;

        return [
            'id' => $this->id,
            'fortia_location_id' => Schema::hasColumn('locations', 'fortia_location_id') ? $this->fortia_location_id : null,
            'company' => [
                'id' => $this->company?->id,
                'name' => $this->company?->name,
            ],
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
            'address' => $this->address,
            'timezone' => $this->timezone,
            'status' => (int) $this->status,
            'status_label' => $this->status ? 'Activa' : 'Inactiva',
            'clocks_count' => $this->clocks_count ?? $this->clocks?->count() ?? 0,
            'active_clocks_count' => (int) ($this->active_clocks_count ?? 0),
            'offline_clocks_count' => (int) ($this->offline_clocks_count ?? 0),
            'last_heartbeat_at' => $lastHeartbeatAt?->toIso8601String(),
            'last_heartbeat_at_display' => $lastHeartbeatAt?->format('Y-m-d H:i:s'),
            'created_at' => $createdAt?->toIso8601String(),
            'created_at_display' => $createdAt?->format('Y-m-d H:i:s'),
            'updated_at' => $updatedAt?->toIso8601String(),
            'updated_at_display' => $updatedAt?->format('Y-m-d H:i:s'),
        ];
    }
}
