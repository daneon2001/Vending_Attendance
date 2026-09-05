<?php

namespace Tests\Unit\Vending;

use App\Enums\Vending\GeofenceShape;
use App\Models\MachineGeofence;
use App\Services\Vending\GeofenceValidationService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GeofenceValidationServiceTest extends TestCase
{
    private GeofenceValidationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new GeofenceValidationService;
    }

    public function test_same_point_has_zero_distance_and_is_inside(): void
    {
        $result = $this->service->validate($this->geofence(), 19.4326, -99.1332, 0);

        $this->assertSame(0.0, $result['distance_m']);
        $this->assertSame('INSIDE', $result['result']);
    }

    public function test_points_are_classified_inside_outside_and_at_the_boundary(): void
    {
        $inside = $this->service->validate($this->geofence(), 19.4327, -99.1332, 2);
        $outside = $this->service->validate($this->geofence(), 19.4336, -99.1332, 2);
        $distance = $this->service->distanceInMeters(19.4326, -99.1332, 19.432959, -99.1332);
        $boundary = $this->service->validate($this->geofence(['radius_m' => (int) ceil($distance)]), 19.432959, -99.1332, 0);

        $this->assertSame('INSIDE', $inside['result']);
        $this->assertSame('OUTSIDE', $outside['result']);
        $this->assertSame('INSIDE', $boundary['result']);
    }

    public function test_low_accuracy_is_uncertain_instead_of_arbitrary_rejection(): void
    {
        $threshold = $this->service->validate($this->geofence(['minimum_acceptable_accuracy_m' => 15]), 19.4327, -99.1332, 50);
        $overlap = $this->service->validate($this->geofence(['minimum_acceptable_accuracy_m' => null]), 19.43295, -99.1332, 20);

        $this->assertSame('UNCERTAIN', $threshold['result']);
        $this->assertSame('ACCURACY_BELOW_REQUIREMENT', $threshold['reason']);
        $this->assertSame('UNCERTAIN', $overlap['result']);
        $this->assertSame('ACCURACY_OVERLAPS_BOUNDARY', $overlap['reason']);
    }

    public function test_shared_mobile_parity_fixtures(): void
    {
        $fixtures = json_decode(
            file_get_contents(dirname(__DIR__, 3).'/tests/Fixtures/vending-geofence-validation.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        foreach ($fixtures as $fixture) {
            $geofence = $this->geofence([
                'center_latitude' => $fixture['geofence']['latitude'],
                'center_longitude' => $fixture['geofence']['longitude'],
                'radius_m' => $fixture['geofence']['radius_m'],
                'minimum_acceptable_accuracy_m' => $fixture['geofence']['minimum_acceptable_accuracy_m'],
                'tolerance_m' => $fixture['geofence']['tolerance_m'],
            ]);
            $result = $this->service->validate(
                $geofence,
                $fixture['location']['latitude'],
                $fixture['location']['longitude'],
                $fixture['location']['accuracy_m'],
                '2026-09-04T12:00:00Z',
            );

            $this->assertSame($fixture['result'], $result['result'], $fixture['name']);
            $this->assertSame($fixture['reason'], $result['reason'], $fixture['name']);
        }
    }

    #[DataProvider('invalidInputProvider')]
    public function test_invalid_inputs_are_rejected(array $attributes, float $latitude, float $longitude, ?float $accuracy): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->validate($this->geofence($attributes), $latitude, $longitude, $accuracy);
    }

    public static function invalidInputProvider(): array
    {
        return [
            'invalid latitude' => [[], 91, -99, 1],
            'zero coordinate' => [[], 0, 0, 1],
            'invalid radius' => [['radius_m' => 0], 19.4326, -99.1332, 1],
            'negative accuracy' => [[], 19.4326, -99.1332, -1],
            'invalid center' => [['center_latitude' => 0, 'center_longitude' => 0], 19.4326, -99.1332, 1],
        ];
    }

    private function geofence(array $attributes = []): MachineGeofence
    {
        return new MachineGeofence(array_merge([
            'shape' => GeofenceShape::CIRCLE->value,
            'center_latitude' => 19.4326,
            'center_longitude' => -99.1332,
            'radius_m' => 40,
            'minimum_acceptable_accuracy_m' => 25,
            'tolerance_m' => 0,
        ], $attributes));
    }
}
