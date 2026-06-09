<?php

namespace App\Services\OnPremise;

use App\Models\Clock;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Location;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class ClockUnitResolver
{
    public function resolve(array $validated, ?Employee $employee = null): ClockUnitResolution
    {
        $providedClockId = $this->normalizeInt($validated['clock_id'] ?? null);
        $providedUnitId = $this->normalizeInt($validated['unit_id'] ?? null);
        $deviceSerial = $this->normalizeString($validated['device_serial'] ?? $validated['serial_number'] ?? null);

        $unitResolution = $this->resolveLocationFromProvidedUnit($providedUnitId);
        if ($unitResolution['failure_code'] !== null) {
            return new ClockUnitResolution(
                clock: null,
                resolvedLocation: null,
                providedClockId: $providedClockId,
                providedUnitId: $providedUnitId,
                deviceSerial: $deviceSerial,
                clockResolutionSource: null,
                unitResolutionSource: null,
                failureCode: $unitResolution['failure_code'],
                failureMessage: $unitResolution['failure_message'],
                unitCandidates: $unitResolution['unit_candidates'],
            );
        }

        $clockFromId = $providedClockId !== null ? $this->findClockById($providedClockId) : null;
        $clockBySerial = $deviceSerial !== null ? $this->resolveClockFromDeviceSerial($deviceSerial) : null;

        if ($providedClockId !== null && $deviceSerial !== null) {
            if ($clockFromId === null && $clockBySerial['failure_code'] !== null) {
                return new ClockUnitResolution(
                    clock: $clockFromId,
                    resolvedLocation: $unitResolution['location'],
                    providedClockId: $providedClockId,
                    providedUnitId: $providedUnitId,
                    deviceSerial: $deviceSerial,
                    clockResolutionSource: 'payload.clock_id',
                    unitResolutionSource: $unitResolution['source'],
                    failureCode: $clockBySerial['failure_code'],
                    failureMessage: $clockBySerial['failure_message'],
                    unitCandidates: $unitResolution['unit_candidates'],
                );
            }

            $serialClock = $clockBySerial['clock'];
            if ($clockFromId && $serialClock && (int) $clockFromId->id !== (int) $serialClock->id) {
                return new ClockUnitResolution(
                    clock: $clockFromId,
                    resolvedLocation: $unitResolution['location'],
                    providedClockId: $providedClockId,
                    providedUnitId: $providedUnitId,
                    deviceSerial: $deviceSerial,
                    clockResolutionSource: 'payload.clock_id',
                    unitResolutionSource: $unitResolution['source'],
                    failureCode: 'clock_identity_mismatch',
                    failureMessage: sprintf(
                        'clock_id=%d and device_serial=%s do not correspond to the same clock.',
                        $providedClockId,
                        $deviceSerial
                    ),
                    unitCandidates: $unitResolution['unit_candidates'],
                );
            }
        }

        if ($providedClockId === null && $deviceSerial !== null && $clockBySerial['failure_code'] !== null) {
            return new ClockUnitResolution(
                clock: null,
                resolvedLocation: $unitResolution['location'],
                providedClockId: $providedClockId,
                providedUnitId: $providedUnitId,
                deviceSerial: $deviceSerial,
                clockResolutionSource: null,
                unitResolutionSource: $unitResolution['source'],
                failureCode: $clockBySerial['failure_code'],
                failureMessage: $clockBySerial['failure_message'],
                unitCandidates: $unitResolution['unit_candidates'],
            );
        }

        $clock = $clockFromId;
        $clockResolutionSource = $clock !== null ? 'payload.clock_id' : null;

        if ($clock === null && ($clockBySerial['clock'] ?? null) instanceof Clock) {
            $clock = $clockBySerial['clock'];
            $clockResolutionSource = (string) $clockBySerial['source'];
        }

        if ($clock === null && $deviceSerial === null) {
            $fallbackUnitId = $unitResolution['location']?->id !== null
                ? (int) $unitResolution['location']->id
                : $providedUnitId;

            if ($fallbackUnitId !== null) {
                $clock = $this->findFallbackClockByLocation($fallbackUnitId);
                if ($clock !== null) {
                    $clockResolutionSource = $unitResolution['location'] !== null
                        ? 'resolved.unit_id_fallback'
                        : 'raw.unit_id_fallback';
                }
            }
        }

        $resolvedLocation = $unitResolution['location'];
        $unitResolutionSource = $unitResolution['source'];

        if ($providedUnitId !== null && $resolvedLocation === null) {
            if ($clock && $clock->location_id !== null && (int) $clock->location_id === $providedUnitId) {
                $resolvedLocation = $this->findLocationById((int) $clock->location_id);
                $unitResolutionSource = 'clock.location_id_compat';
            } elseif ($employee?->base_location_id !== null && (int) $employee->base_location_id === $providedUnitId) {
                $resolvedLocation = $this->findLocationById((int) $employee->base_location_id);
                $unitResolutionSource = $resolvedLocation !== null
                    ? 'employee.base_location_id'
                    : 'employee.base_location_id_compat';
            }
        }

        if ($providedUnitId === null) {
            if ($clock && $clock->location_id !== null) {
                $resolvedLocation = $this->findLocationById((int) $clock->location_id);
                $unitResolutionSource = 'clock.location_id';
            } elseif ($employee?->base_location_id !== null) {
                $employeeLocation = $this->resolveLocationFromProvidedUnit((int) $employee->base_location_id);
                $resolvedLocation = $employeeLocation['location'];
                $unitResolutionSource = $employeeLocation['source'] ?? 'employee.base_location_id';

                if ($resolvedLocation === null && (int) $employee->base_location_id > 0) {
                    $unitResolutionSource = 'employee.base_location_id_compat';
                }
            }
        }

        return new ClockUnitResolution(
            clock: $clock,
            resolvedLocation: $resolvedLocation,
            providedClockId: $providedClockId,
            providedUnitId: $providedUnitId,
            deviceSerial: $deviceSerial,
            clockResolutionSource: $clockResolutionSource,
            unitResolutionSource: $unitResolutionSource,
            unitCandidates: $unitResolution['unit_candidates'],
        );
    }

    /**
     * @return array{clock:?Clock,source:?string,failure_code:?string,failure_message:?string}
     */
    private function resolveClockFromDeviceSerial(string $deviceSerial): array
    {
        $directMatches = Clock::query()
            ->with('location')
            ->where('serial_number', $deviceSerial)
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($directMatches->count() > 1) {
            return [
                'clock' => null,
                'source' => null,
                'failure_code' => 'device_serial_ambiguous',
                'failure_message' => sprintf(
                    'device_serial=%s matches multiple clocks by serial_number.',
                    $deviceSerial
                ),
            ];
        }

        if ($directMatches->count() === 1) {
            return [
                'clock' => $directMatches->first(),
                'source' => 'clock.serial_number',
                'failure_code' => null,
                'failure_message' => null,
            ];
        }

        if (Schema::hasTable('devices')) {
            $device = Device::query()
                ->where('device_serial', $deviceSerial)
                ->first();

            if ($device?->clock_id) {
                $clock = $this->findClockById((int) $device->clock_id);
                if ($clock !== null) {
                    return [
                        'clock' => $clock,
                        'source' => 'device.device_serial',
                        'failure_code' => null,
                        'failure_message' => null,
                    ];
                }
            }
        }

        return [
            'clock' => null,
            'source' => null,
            'failure_code' => 'device_serial_not_resolved',
            'failure_message' => sprintf(
                'The provided device_serial %s could not be resolved to a registered clock.',
                $deviceSerial
            ),
        ];
    }

    /**
     * @return array{location:?Location,source:?string,failure_code:?string,failure_message:?string,unit_candidates:array<int,array<string,mixed>>}
     */
    private function resolveLocationFromProvidedUnit(?int $providedUnitId): array
    {
        if ($providedUnitId === null || $providedUnitId <= 0) {
            return [
                'location' => null,
                'source' => null,
                'failure_code' => null,
                'failure_message' => null,
                'unit_candidates' => [],
            ];
        }

        $matches = Location::query()
            ->where(function ($query) use ($providedUnitId): void {
                $query->whereKey($providedUnitId);

                if (Schema::hasColumn('locations', 'fortia_location_id')) {
                    $query->orWhere('fortia_location_id', $providedUnitId);
                }

                if (Schema::hasColumn('locations', 'code')) {
                    $query->orWhere('code', (string) $providedUnitId);
                }
            })
            ->orderBy('id')
            ->get();

        if ($matches->isEmpty()) {
            return [
                'location' => null,
                'source' => null,
                'failure_code' => null,
                'failure_message' => null,
                'unit_candidates' => [],
            ];
        }

        $candidates = $this->buildUnitCandidates($matches, $providedUnitId);
        $candidateIds = collect($candidates)
            ->pluck('id')
            ->filter(fn ($id) => is_numeric($id))
            ->unique()
            ->values();

        if ($candidateIds->count() > 1) {
            return [
                'location' => null,
                'source' => null,
                'failure_code' => 'unit_resolution_ambiguous',
                'failure_message' => sprintf(
                    'The provided unit_id=%d is ambiguous across multiple locations.',
                    $providedUnitId
                ),
                'unit_candidates' => $candidates,
            ];
        }

        /** @var Location $location */
        $location = $matches->firstWhere('id', (int) $candidateIds->first()) ?? $matches->first();
        $matchedBy = collect($candidates)
            ->firstWhere('id', (int) $location->id)['matched_by'] ?? ['locations.id'];

        return [
            'location' => $location,
            'source' => implode('|', $matchedBy),
            'failure_code' => null,
            'failure_message' => null,
            'unit_candidates' => $candidates,
        ];
    }

    private function findClockById(int $clockId): ?Clock
    {
        return Clock::query()
            ->with('location')
            ->find($clockId);
    }

    private function findFallbackClockByLocation(int $locationId): ?Clock
    {
        return Clock::query()
            ->with('location')
            ->where('location_id', $locationId)
            ->orderByDesc('updated_at')
            ->orderBy('id')
            ->first();
    }

    private function findLocationById(int $locationId): ?Location
    {
        return Location::query()->find($locationId);
    }

    private function normalizeInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param  Collection<int, Location>  $matches
     * @return array<int, array<string, mixed>>
     */
    private function buildUnitCandidates(Collection $matches, int $providedUnitId): array
    {
        return $matches
            ->map(function (Location $location) use ($providedUnitId): array {
                $matchedBy = [];

                if ((int) $location->id === $providedUnitId) {
                    $matchedBy[] = 'locations.id';
                }

                if (
                    Schema::hasColumn('locations', 'fortia_location_id')
                    && (int) ($location->fortia_location_id ?? 0) === $providedUnitId
                ) {
                    $matchedBy[] = 'locations.fortia_location_id';
                }

                if (
                    Schema::hasColumn('locations', 'code')
                    && trim((string) ($location->code ?? '')) === (string) $providedUnitId
                ) {
                    $matchedBy[] = 'locations.code';
                }

                return [
                    'id' => (int) $location->id,
                    'fortia_location_id' => $location->fortia_location_id !== null ? (int) $location->fortia_location_id : null,
                    'code' => $location->code,
                    'name' => $location->name,
                    'matched_by' => $matchedBy,
                ];
            })
            ->values()
            ->all();
    }
}
