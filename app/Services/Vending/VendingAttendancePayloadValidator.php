<?php

namespace App\Services\Vending;

use App\Enums\Vending\AttendanceGeofenceResult;
use App\Enums\Vending\VendingAttendanceEventType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class VendingAttendancePayloadValidator
{
    private const TOP_LEVEL_KEYS = [
        'event_uuid', 'employee_id', 'event_type', 'captured_at',
        'employee_manifest_version', 'configuration_version', 'assignment_uuid',
        'device_timezone', 'location', 'geofence',
    ];

    private const LOCATION_KEYS = ['latitude', 'longitude', 'accuracy_m'];

    private const GEOFENCE_KEYS = ['version', 'edge_result'];

    /** @return array{valid:bool,data?:array,error_fields?:array<int,string>} */
    public function validate(array $payload): array
    {
        $unknown = array_diff(array_keys($payload), self::TOP_LEVEL_KEYS);
        $unknownLocation = is_array($payload['location'] ?? null)
            ? array_diff(array_keys($payload['location']), self::LOCATION_KEYS)
            : [];
        $unknownGeofence = is_array($payload['geofence'] ?? null)
            ? array_diff(array_keys($payload['geofence']), self::GEOFENCE_KEYS)
            : [];

        if ($unknown !== [] || $unknownLocation !== [] || $unknownGeofence !== []) {
            return [
                'valid' => false,
                'error_fields' => array_values(array_unique(array_merge($unknown, $unknownLocation, $unknownGeofence))),
            ];
        }

        $validator = Validator::make($payload, [
            'event_uuid' => ['required', 'uuid'],
            'employee_id' => ['required', 'integer', 'min:1'],
            'event_type' => ['required', Rule::enum(VendingAttendanceEventType::class)],
            'captured_at' => ['required', 'date'],
            'employee_manifest_version' => ['required', 'integer', 'min:1'],
            'configuration_version' => ['required', 'integer', 'min:1'],
            'assignment_uuid' => ['required', 'uuid'],
            'device_timezone' => ['nullable', 'timezone:all'],
            'location' => ['nullable', 'array'],
            'location.latitude' => ['required_with:location', 'numeric', 'between:-1000,1000'],
            'location.longitude' => ['required_with:location', 'numeric', 'between:-1000,1000'],
            'location.accuracy_m' => ['required_with:location', 'numeric', 'between:-1000000,1000000'],
            'geofence' => ['nullable', 'array'],
            'geofence.version' => ['required_with:geofence', 'integer', 'min:1'],
            'geofence.edge_result' => ['nullable', Rule::in(AttendanceGeofenceResult::edgeValues())],
        ]);

        if ($validator->fails()) {
            return ['valid' => false, 'error_fields' => array_keys($validator->errors()->toArray())];
        }

        $valid = $validator->validated();

        return [
            'valid' => true,
            'data' => [
                'event_uuid' => strtolower((string) $valid['event_uuid']),
                'employee_id' => (int) $valid['employee_id'],
                'event_type' => (string) $valid['event_type'],
                'captured_at' => CarbonImmutable::parse($valid['captured_at'])->utc(),
                'employee_manifest_version' => (int) $valid['employee_manifest_version'],
                'configuration_version' => (int) $valid['configuration_version'],
                'assignment_uuid' => strtolower((string) $valid['assignment_uuid']),
                'device_timezone' => $valid['device_timezone'] ?? null,
                'location' => isset($valid['location']) ? [
                    'latitude' => (float) $valid['location']['latitude'],
                    'longitude' => (float) $valid['location']['longitude'],
                    'accuracy_m' => (float) $valid['location']['accuracy_m'],
                ] : null,
                'geofence' => isset($valid['geofence']) ? [
                    'version' => (int) $valid['geofence']['version'],
                    'edge_result' => isset($valid['geofence']['edge_result'])
                        ? (string) $valid['geofence']['edge_result']
                        : null,
                ] : null,
            ],
        ];
    }

    public function hashablePayload(array $validated): array
    {
        $payload = $validated;
        $payload['captured_at'] = $validated['captured_at']->format('Y-m-d\TH:i:s.u\Z');

        return $payload;
    }
}
