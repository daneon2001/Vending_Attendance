<?php

namespace App\Services\OnPremise;

use App\Models\Clock;
use App\Models\Location;

class ClockUnitResolution
{
    /**
     * @param  array<int, array<string, mixed>>  $unitCandidates
     */
    public function __construct(
        public readonly ?Clock $clock,
        public readonly ?Location $resolvedLocation,
        public readonly ?int $providedClockId,
        public readonly ?int $providedUnitId,
        public readonly ?string $deviceSerial,
        public readonly ?string $clockResolutionSource,
        public readonly ?string $unitResolutionSource,
        public readonly ?string $failureCode = null,
        public readonly ?string $failureMessage = null,
        public readonly array $unitCandidates = [],
    ) {
    }

    public function hasFailure(): bool
    {
        return $this->failureCode !== null;
    }

    public function resolvedUnitId(): ?int
    {
        if ($this->resolvedLocation?->id !== null) {
            return (int) $this->resolvedLocation->id;
        }

        if (
            $this->providedUnitId !== null
            && in_array($this->unitResolutionSource, ['clock.location_id_compat', 'employee.base_location_id_compat'], true)
        ) {
            return $this->providedUnitId;
        }

        return null;
    }

    public function resolvedClockId(): ?int
    {
        if ($this->clock?->id !== null) {
            return (int) $this->clock->id;
        }

        return null;
    }

    public function clockLocationId(): ?int
    {
        if ($this->clock?->location_id !== null) {
            return (int) $this->clock->location_id;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toAuditMetadata(): array
    {
        $clockLocation = $this->clock?->relationLoaded('location') ? $this->clock->location : null;

        return [
            'provided_clock_id' => $this->providedClockId,
            'resolved_clock_id' => $this->resolvedClockId(),
            'device_serial' => $this->deviceSerial,
            'clock_serial_number' => $this->clock?->serial_number,
            'clock_location_id' => $this->clockLocationId(),
            'provided_unit_id' => $this->providedUnitId,
            'resolved_unit_id' => $this->resolvedUnitId(),
            'unit_resolution_source' => $this->unitResolutionSource,
            'clock_resolution_source' => $this->clockResolutionSource,
            'location_fortia_id' => $clockLocation?->fortia_location_id,
            'location_code' => $clockLocation?->code,
            'unit_candidates' => $this->unitCandidates,
            'failure_code' => $this->failureCode,
        ];
    }
}
