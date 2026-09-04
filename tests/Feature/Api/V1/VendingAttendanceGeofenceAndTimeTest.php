<?php

namespace Tests\Feature\Api\V1;

use App\Models\VendingAttendanceEvent;
use App\Services\Vending\VendingAttendanceMetricsService;

class VendingAttendanceGeofenceAndTimeTest extends VendingDeviceApiTestCase
{
    public function test_server_geofence_preserves_inside_outside_uncertain_and_missing_or_invalid_evidence(): void
    {
        $machine = $this->machine('ATT-GEOFENCE');
        $employee = $this->employee();
        $assignment = $this->assignment($machine, $employee);
        $geofence = $this->geofence($machine, ['minimum_acceptable_accuracy_m' => 30, 'tolerance_m' => 0]);
        $provisioned = $this->provisionedDevice($machine, 'ATT-GEOFENCE-DEVICE');

        $inside = $this->send($provisioned, $this->attendancePayload($machine, $employee, $assignment, $geofence));
        $inside->assertJsonPath('server_geofence_result', 'INSIDE');

        $outsidePayload = $this->attendancePayload($machine, $employee, $assignment, $geofence, [
            'location' => ['latitude' => 19.45, 'longitude' => -99.1332, 'accuracy_m' => 3],
            'geofence' => ['version' => $geofence->version, 'edge_result' => 'OUTSIDE'],
        ]);
        $this->send($provisioned, $outsidePayload)->assertJsonPath('server_geofence_result', 'OUTSIDE');

        $uncertainPayload = $this->attendancePayload($machine, $employee, $assignment, $geofence, [
            'location' => ['latitude' => 19.4326, 'longitude' => -99.1332, 'accuracy_m' => 100],
            'geofence' => ['version' => $geofence->version, 'edge_result' => 'UNCERTAIN'],
        ]);
        $this->send($provisioned, $uncertainPayload)->assertJsonPath('server_geofence_result', 'UNCERTAIN');

        $missingPayload = $this->attendancePayload($machine, $employee, $assignment, $geofence, ['location' => null]);
        $this->send($provisioned, $missingPayload)->assertJsonPath('server_geofence_result', 'NOT_EVALUATED');

        $zeroPayload = $this->attendancePayload($machine, $employee, $assignment, $geofence, [
            'location' => ['latitude' => 0, 'longitude' => 0, 'accuracy_m' => 5],
        ]);
        $this->send($provisioned, $zeroPayload)->assertJsonPath('server_geofence_result', 'NOT_EVALUATED');

        $this->assertDatabaseHas('vending_attendance_events', [
            'event_uuid' => $uncertainPayload['event_uuid'],
            'location_evidence_status' => 'LOW_ACCURACY',
            'server_geofence_result' => 'UNCERTAIN',
        ]);
        $this->assertDatabaseHas('vending_attendance_events', [
            'event_uuid' => $missingPayload['event_uuid'],
            'location_evidence_status' => 'MISSING',
            'server_geofence_result' => 'NOT_EVALUATED',
        ]);
        $this->assertDatabaseHas('vending_attendance_events', [
            'event_uuid' => $zeroPayload['event_uuid'],
            'location_evidence_status' => 'INVALID',
            'server_geofence_result' => 'NOT_EVALUATED',
        ]);
    }

    public function test_historical_geofence_version_is_used_and_edge_server_mismatch_is_audited(): void
    {
        $machine = $this->machine('ATT-GEOFENCE-HISTORY');
        $employee = $this->employee();
        $assignment = $this->assignment($machine, $employee);
        $versionOne = $this->geofence($machine, [
            'center_latitude' => 19.4326,
            'center_longitude' => -99.1332,
            'radius_m' => 40,
        ]);
        $versionTwo = $this->geofence($machine, [
            'center_latitude' => 20.0,
            'center_longitude' => -100.0,
            'radius_m' => 20,
        ]);
        $provisioned = $this->provisionedDevice($machine, 'ATT-GEOFENCE-HISTORY-DEVICE');
        $payload = $this->attendancePayload($machine, $employee, $assignment, $versionOne, [
            'geofence' => ['version' => 1, 'edge_result' => 'OUTSIDE'],
            'location' => ['latitude' => 19.4326, 'longitude' => -99.1332, 'accuracy_m' => 2],
        ]);

        $this->send($provisioned, $payload)
            ->assertJsonPath('server_geofence_result', 'INSIDE')
            ->assertJsonPath('warnings.0', 'EDGE_SERVER_GEOFENCE_MISMATCH');

        $this->assertSame(2, $versionTwo->version);
        $this->assertDatabaseHas('vending_attendance_events', [
            'event_uuid' => $payload['event_uuid'],
            'geofence_id' => $versionOne->id,
            'geofence_version' => 1,
            'geofence_radius_snapshot' => 40,
            'geofence_discrepancy' => true,
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'attendance.geofence_mismatch']);

        $unknown = $this->attendancePayload($machine, $employee, $assignment, $versionTwo, [
            'geofence' => ['version' => 999, 'edge_result' => 'INSIDE'],
        ]);
        $this->send($provisioned, $unknown)
            ->assertJsonPath('server_geofence_result', 'NOT_EVALUATED')
            ->assertJsonPath('warnings.0', 'UNKNOWN_GEOFENCE_VERSION');
    }

    public function test_event_time_supports_offline_delay_future_tolerance_and_clock_drift_without_rewriting_evidence(): void
    {
        $machine = $this->machine('ATT-TIME');
        $employee = $this->employee();
        $assignment = $this->assignment($machine, $employee, ['valid_from' => now()->subDays(10)]);
        $geofence = $this->geofence($machine);
        $provisioned = $this->provisionedDevice($machine, 'ATT-TIME-DEVICE');
        $provisioned['device']->forceFill(['clock_drift_seconds' => 91])->save();

        foreach ([now(), now()->subHours(4), now()->subDays(3), now()->addSeconds(120)] as $capturedAt) {
            $payload = $this->attendancePayload($machine, $employee, $assignment, $geofence, [
                'captured_at' => $capturedAt->utc()->toIso8601String(),
            ]);
            $this->send($provisioned, $payload);
        }

        $future = $this->attendancePayload($machine, $employee, $assignment, $geofence, [
            'captured_at' => now()->addMinutes(10)->utc()->toIso8601String(),
        ]);
        $this->signedDeviceRequest('POST', '/api/v1/device/attendance/events', $future, $provisioned['device']->fresh(), $provisioned['credential'])
            ->assertUnprocessable()
            ->assertJsonPath('status', 'REJECTED')
            ->assertJsonPath('error_code', 'INVALID_TIMESTAMP');

        $events = VendingAttendanceEvent::query()->orderBy('id')->get();
        $this->assertCount(4, $events);
        $this->assertGreaterThanOrEqual(4 * 3600, $events[1]->sync_delay_seconds);
        $this->assertGreaterThanOrEqual(3 * 86400, $events[2]->sync_delay_seconds);
        $this->assertLessThan(0, $events[3]->sync_delay_seconds);
        $this->assertSame(91, $events[0]->device_clock_drift_seconds);
        $this->assertNotEquals($events[1]->captured_at_device, $events[1]->received_at_server);
    }

    public function test_observability_summary_exposes_recent_delay_mismatch_and_cumulative_receipts(): void
    {
        $machine = $this->machine('ATT-METRICS');
        $employee = $this->employee();
        $assignment = $this->assignment($machine, $employee, ['valid_from' => now()->subDays(2)]);
        $geofence = $this->geofence($machine);
        $provisioned = $this->provisionedDevice($machine, 'ATT-METRICS-DEVICE');
        $payload = $this->attendancePayload($machine, $employee, $assignment, $geofence, [
            'captured_at' => now()->subHour()->utc()->toIso8601String(),
            'geofence' => ['version' => $geofence->version, 'edge_result' => 'OUTSIDE'],
        ]);

        $this->send($provisioned, $payload);
        $this->signedDeviceRequest('POST', '/api/v1/device/attendance/events', $payload, $provisioned['device']->fresh(), $provisioned['credential'])
            ->assertOk()->assertJsonPath('status', 'DUPLICATE');
        $invalid = array_replace($payload, ['event_uuid' => 'not-a-uuid']);
        $this->signedDeviceRequest('POST', '/api/v1/device/attendance/events', $invalid, $provisioned['device']->fresh(), $provisioned['credential'])
            ->assertUnprocessable();

        $summary = app(VendingAttendanceMetricsService::class)->summary($provisioned['device']->fresh());
        $this->assertSame(1, $summary['events_received']);
        $this->assertSame(1, $summary['stored_total']);
        $this->assertSame(1, $summary['duplicate_total']);
        $this->assertSame(1, $summary['rejected_total']);
        $this->assertSame(1, $summary['geofence_mismatches']);
        $this->assertGreaterThanOrEqual(3600, $summary['sync_delay_average_seconds']);
        $this->assertNotNull($summary['last_attendance_received_at']);
    }

    private function send(array $provisioned, array $payload)
    {
        return $this->signedDeviceRequest(
            'POST',
            '/api/v1/device/attendance/events',
            $payload,
            $provisioned['device']->fresh(),
            $provisioned['credential'],
        )->assertCreated();
    }
}
