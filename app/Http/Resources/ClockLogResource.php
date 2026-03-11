<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClockLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'clock_id' => $this->clock_id,
            'event_type' => $this->event_type,
            'level' => $this->level,
            'source' => $this->source,
            'message' => $this->message,
            'payload' => $this->payload,
            'occurred_at' => optional($this->occurred_at)?->toIso8601String(),
        ];
    }
}
