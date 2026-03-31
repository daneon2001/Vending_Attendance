<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'status' => (int) $this->status,
            'status_label' => $this->status ? 'Activa' : 'Inactiva',
            'units_count' => (int) ($this->units_count ?? $this->locations_count ?? 0),
            'clocks_count' => (int) ($this->clocks_count ?? 0),
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];
    }
}
