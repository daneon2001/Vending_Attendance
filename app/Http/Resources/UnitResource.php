<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UnitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
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
            'created_at' => optional($this->created_at)?->toIso8601String(),
        ];
    }
}
