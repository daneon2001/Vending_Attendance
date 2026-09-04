<?php

namespace App\Services\Vending;

use App\Enums\Vending\GeofenceShape;
use App\Enums\Vending\GeofenceValidationResult;
use App\Models\MachineGeofence;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class GeofenceValidationService
{
    private const EARTH_RADIUS_M = 6371008.8;

    public function validate(
        MachineGeofence $geofence,
        float $latitude,
        float $longitude,
        ?float $accuracy = null,
        DateTimeInterface|string|null $capturedAt = null,
    ): array {
        $this->assertCoordinate($latitude, $longitude);

        if (($geofence->shape instanceof GeofenceShape ? $geofence->shape : GeofenceShape::tryFrom((string) $geofence->shape)) !== GeofenceShape::CIRCLE) {
            throw new InvalidArgumentException('Only circular geofences are supported in this phase.');
        }

        $centerLatitude = (float) $geofence->center_latitude;
        $centerLongitude = (float) $geofence->center_longitude;
        $this->assertCoordinate($centerLatitude, $centerLongitude);

        $radius = (float) $geofence->radius_m;
        $tolerance = (float) ($geofence->tolerance_m ?? 0);
        $accuracy ??= 0.0;

        if ($radius <= 0) {
            throw new InvalidArgumentException('Geofence radius must be greater than zero.');
        }
        if ($accuracy < 0 || $tolerance < 0) {
            throw new InvalidArgumentException('Accuracy and tolerance cannot be negative.');
        }

        $distance = $this->distanceInMeters($centerLatitude, $centerLongitude, $latitude, $longitude);
        $effectiveRadius = $radius + $tolerance;
        $minimumPossibleDistance = max(0.0, $distance - $accuracy);
        $maximumPossibleDistance = $distance + $accuracy;
        $accuracyLimit = $geofence->minimum_acceptable_accuracy_m;

        if ($accuracyLimit !== null && $accuracy > (float) $accuracyLimit) {
            $result = GeofenceValidationResult::UNCERTAIN;
            $reason = 'ACCURACY_BELOW_REQUIREMENT';
        } elseif ($maximumPossibleDistance <= $effectiveRadius) {
            $result = GeofenceValidationResult::INSIDE;
            $reason = 'DEFINITELY_INSIDE';
        } elseif ($minimumPossibleDistance > $effectiveRadius) {
            $result = GeofenceValidationResult::OUTSIDE;
            $reason = 'DEFINITELY_OUTSIDE';
        } else {
            $result = GeofenceValidationResult::UNCERTAIN;
            $reason = 'ACCURACY_OVERLAPS_BOUNDARY';
        }

        return [
            'distance_m' => round($distance, 2),
            'effective_distance_m' => round($maximumPossibleDistance, 2),
            'radius_m' => round($radius, 2),
            'accuracy_m' => round($accuracy, 2),
            'tolerance_m' => round($tolerance, 2),
            'minimum_acceptable_accuracy_m' => $accuracyLimit !== null ? round((float) $accuracyLimit, 2) : null,
            'result' => $result->value,
            'reason' => $reason,
            'captured_at' => Carbon::parse($capturedAt ?? 'now')->toIso8601String(),
        ];
    }

    public function distanceInMeters(float $fromLatitude, float $fromLongitude, float $toLatitude, float $toLongitude): float
    {
        $this->assertCoordinate($fromLatitude, $fromLongitude);
        $this->assertCoordinate($toLatitude, $toLongitude);

        $latitudeDelta = deg2rad($toLatitude - $fromLatitude);
        $longitudeDelta = deg2rad($toLongitude - $fromLongitude);
        $fromLatitudeRadians = deg2rad($fromLatitude);
        $toLatitudeRadians = deg2rad($toLatitude);

        $a = sin($latitudeDelta / 2) ** 2
            + cos($fromLatitudeRadians) * cos($toLatitudeRadians) * sin($longitudeDelta / 2) ** 2;

        return self::EARTH_RADIUS_M * 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
    }

    private function assertCoordinate(float $latitude, float $longitude): void
    {
        if (! is_finite($latitude) || ! is_finite($longitude) || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            throw new InvalidArgumentException('Invalid geographic coordinates.');
        }

        if ($latitude === 0.0 && $longitude === 0.0) {
            throw new InvalidArgumentException('The 0,0 coordinate is not accepted for operational validation.');
        }
    }
}
