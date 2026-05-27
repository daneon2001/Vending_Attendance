<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class ClockResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $lastHeartbeatAt = $this->last_heartbeat_at instanceof Carbon
            ? $this->last_heartbeat_at->copy()
            : null;
        $secondsSinceHeartbeat = $lastHeartbeatAt?->diffInSeconds(now());
        $connectionStatus = $this->resolvedMonitoringStatus();
        $programStatus = (string) ($this->onprem_program_status ?? 'offline');

        return [
            'id' => $this->id,
            'clock_name' => $this->clock_name,
            'serial_number' => $this->serial_number,
            'firmware_version' => $this->firmware_version,
            'ip_address' => $this->ip_address,
            'type_inout' => $this->type_inout,
            'status' => $this->status,
            'status_label' => $this->status ? 'Activo' : 'Inactivo',
            'monitoring_status' => $connectionStatus,
            'connection_status_label' => match ($connectionStatus) {
                'inactive' => 'Inactivo',
                'online' => 'En linea',
                'warning' => 'Con alerta',
                default => 'Sin conexion',
            },
            'connection_status_color' => match ($connectionStatus) {
                'inactive' => 'slate',
                'online' => 'emerald',
                'warning' => 'amber',
                default => 'rose',
            },
            'monitoring_message' => $this->last_status_message,
            'last_seen_ip' => $this->last_seen_ip,
            'program_status' => $programStatus,
            'onprem_program_status' => $programStatus,
            'is_online' => $this->is_online,
            'last_heartbeat_at' => optional($lastHeartbeatAt)?->toIso8601String(),
            'last_heartbeat_human' => $lastHeartbeatAt?->diffForHumans(now(), [
                'parts' => 2,
                'short' => true,
                'syntax' => \Carbon\CarbonInterface::DIFF_RELATIVE_TO_NOW,
            ]),
            'seconds_since_last_heartbeat' => $secondsSinceHeartbeat,
            'minutes_since_last_heartbeat' => $secondsSinceHeartbeat !== null ? intdiv($secondsSinceHeartbeat, 60) : null,
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
