<?php

namespace Tests\Feature\Api;

class OnPremAttendanceStoredTest extends OnPremApiTestCase
{
    public function test_event_is_stored_with_expected_contract_response(): void
    {
        $fixture = $this->seedDeviceFixture('CH-XOCH-001', 'secret-stored');
        $employeeId = $this->seedCollaborator(88001, $fixture['company_id'], $fixture['unit_id']);

        $payload = [
            'device_serial' => $fixture['device_serial'],
            'unit_id' => $fixture['unit_id'],
            'events' => [
                [
                    'local_event_id' => '11111111-1111-1111-1111-111111111111',
                    'collaborator_id' => $employeeId,
                    'punched_at_local' => '2026-02-18T08:02:00',
                    'timezone' => 'America/Mexico_City',
                    'punched_at_utc' => '2026-02-18T14:02:00Z',
                    'source' => 'FINGERPRINT',
                    'type_inout' => 'INOUT',
                    'quality' => 78,
                    'meta' => ['scanner' => 'S1'],
                ],
            ],
        ];

        $response = $this->signedJsonRequest(
            'POST',
            '/api/onprem/attendances',
            $payload,
            $fixture['device_serial'],
            $fixture['secret'],
        );

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('received.0.local_event_id', '11111111-1111-1111-1111-111111111111')
            ->assertJsonPath('received.0.stored', true)
            ->assertJsonPath('received.0.status', 'STORED')
            ->assertJsonPath('received.0.reason', null);

        $remoteId = $response->json('received.0.remote_id');
        $this->assertNotNull($remoteId);

        $this->assertDatabaseHas('attendances_raw', [
            'remote_event_id' => $remoteId,
            'device_serial' => $fixture['device_serial'],
            'local_event_id' => '11111111-1111-1111-1111-111111111111',
            'collaborator_id' => $employeeId,
            'unit_id' => $fixture['unit_id'],
            'tz' => 'America/Mexico_City',
        ]);

        $this->assertDatabaseHas('attendance_logs', [
            'local_id' => '11111111-1111-1111-1111-111111111111',
            'employee_id' => $employeeId,
            'fortia_employee_id' => 88001,
            'device_id' => $fixture['clock_id'],
        ]);
    }

    public function test_event_prefers_root_fortia_employee_id_over_collaborator_id_when_present(): void
    {
        $fixture = $this->seedDeviceFixture('CH-XOCH-001A', 'secret-stored-fortia');
        $employeeId = $this->seedCollaborator(1363, $fixture['company_id'], $fixture['unit_id'], [
            'id' => 11,
            'name' => 'Colaborador Correcto',
            'full_name' => 'Colaborador Correcto',
        ]);

        $payload = [
            'device_serial' => $fixture['device_serial'],
            'unit_id' => $fixture['unit_id'],
            'events' => [
                [
                    'local_event_id' => '11111111-1111-1111-1111-111111111112',
                    'collaborator_id' => 11,
                    'fortia_employee_id' => '1363',
                    'employee_number' => '1363',
                    'punched_at_local' => '2026-02-18T08:03:00',
                    'timezone' => 'America/Mexico_City',
                    'punched_at_utc' => '2026-02-18T14:03:00Z',
                    'source' => 'FACEID',
                    'type_inout' => 'INOUT',
                    'quality' => 88,
                ],
            ],
        ];

        $response = $this->signedJsonRequest(
            'POST',
            '/api/onprem/attendances',
            $payload,
            $fixture['device_serial'],
            $fixture['secret'],
        );

        $response->assertOk()
            ->assertJsonPath('received.0.status', 'STORED');

        $this->assertDatabaseHas('attendance_logs', [
            'local_id' => '11111111-1111-1111-1111-111111111112',
            'employee_id' => $employeeId,
            'fortia_employee_id' => 1363,
        ]);
    }

    public function test_event_uses_fortia_employee_id_even_when_collaborator_id_matches_another_internal_employee(): void
    {
        $fixture = $this->seedDeviceFixture('CH-XOCH-001B', 'secret-stored-collision');
        $this->seedCollaborator(99991, $fixture['company_id'], $fixture['unit_id'], [
            'id' => 1363,
            'name' => 'Empleado Interno 1363',
            'full_name' => 'Empleado Interno 1363',
        ]);
        $correctEmployeeId = $this->seedCollaborator(15557, $fixture['company_id'], $fixture['unit_id'], [
            'id' => 11,
            'name' => 'Empleado Fortia 15557',
            'full_name' => 'Empleado Fortia 15557',
        ]);

        $payload = [
            'device_serial' => $fixture['device_serial'],
            'unit_id' => $fixture['unit_id'],
            'events' => [
                [
                    'local_event_id' => '11111111-1111-1111-1111-111111111113',
                    'collaborator_id' => 1363,
                    'fortia_employee_id' => '15557',
                    'punched_at_local' => '2026-02-18T08:04:00',
                    'timezone' => 'America/Mexico_City',
                    'punched_at_utc' => '2026-02-18T14:04:00Z',
                    'source' => 'FACEID',
                    'type_inout' => 'INOUT',
                    'quality' => 91,
                ],
            ],
        ];

        $response = $this->signedJsonRequest(
            'POST',
            '/api/onprem/attendances',
            $payload,
            $fixture['device_serial'],
            $fixture['secret'],
        );

        $response->assertOk()
            ->assertJsonPath('received.0.status', 'STORED');

        $this->assertDatabaseHas('attendance_logs', [
            'local_id' => '11111111-1111-1111-1111-111111111113',
            'employee_id' => $correctEmployeeId,
            'fortia_employee_id' => 15557,
        ]);
    }

    public function test_event_prefers_explicit_fortia_identifier_when_collaborator_id_is_ambiguous(): void
    {
        $fixture = $this->seedDeviceFixture('CH-XOCH-001B2', 'secret-stored-collision-same');
        $this->seedCollaborator(99992, $fixture['company_id'], $fixture['unit_id'], [
            'id' => 1363,
            'name' => 'Empleado Interno 1363',
            'full_name' => 'Empleado Interno 1363',
        ]);
        $correctEmployeeId = $this->seedCollaborator(1363, $fixture['company_id'], $fixture['unit_id'], [
            'id' => 11,
            'name' => 'Empleado Fortia 1363',
            'full_name' => 'Empleado Fortia 1363',
        ]);

        $payload = [
            'device_serial' => $fixture['device_serial'],
            'unit_id' => $fixture['unit_id'],
            'events' => [
                [
                    'local_event_id' => '11111111-1111-1111-1111-111111111115',
                    'collaborator_id' => 1363,
                    'fortia_employee_id' => '1363',
                    'employee_number' => '1363',
                    'punched_at_local' => '2026-02-18T08:04:30',
                    'timezone' => 'America/Mexico_City',
                    'punched_at_utc' => '2026-02-18T14:04:30Z',
                    'source' => 'FACEID',
                    'type_inout' => 'INOUT',
                ],
            ],
        ];

        $response = $this->signedJsonRequest(
            'POST',
            '/api/onprem/attendances',
            $payload,
            $fixture['device_serial'],
            $fixture['secret'],
        );

        $response->assertOk()
            ->assertJsonPath('received.0.status', 'STORED');

        $this->assertDatabaseHas('attendance_logs', [
            'local_id' => '11111111-1111-1111-1111-111111111115',
            'employee_id' => $correctEmployeeId,
            'fortia_employee_id' => 1363,
        ]);
    }

    public function test_event_uses_legacy_internal_collaborator_id_when_operational_identifiers_are_missing(): void
    {
        $fixture = $this->seedDeviceFixture('CH-XOCH-001C', 'secret-stored-legacy');
        $employeeId = $this->seedCollaborator(15558, $fixture['company_id'], $fixture['unit_id'], [
            'id' => 77,
        ]);

        $payload = [
            'device_serial' => $fixture['device_serial'],
            'unit_id' => $fixture['unit_id'],
            'events' => [
                [
                    'local_event_id' => '11111111-1111-1111-1111-111111111114',
                    'collaborator_id' => $employeeId,
                    'punched_at_local' => '2026-02-18T08:05:00',
                    'timezone' => 'America/Mexico_City',
                    'punched_at_utc' => '2026-02-18T14:05:00Z',
                    'source' => 'FACEID',
                    'type_inout' => 'INOUT',
                ],
            ],
        ];

        $response = $this->signedJsonRequest(
            'POST',
            '/api/onprem/attendances',
            $payload,
            $fixture['device_serial'],
            $fixture['secret'],
        );

        $response->assertOk()
            ->assertJsonPath('received.0.status', 'STORED');

        $this->assertDatabaseHas('attendance_logs', [
            'local_id' => '11111111-1111-1111-1111-111111111114',
            'employee_id' => $employeeId,
            'fortia_employee_id' => 15558,
        ]);
    }

    public function test_event_uses_legacy_fortia_collaborator_id_when_internal_id_is_missing(): void
    {
        $fixture = $this->seedDeviceFixture('CH-XOCH-001D', 'secret-stored-legacy-fortia');
        $employeeId = $this->seedCollaborator(1363, $fixture['company_id'], $fixture['unit_id'], [
            'id' => 11,
        ]);

        $payload = [
            'device_serial' => $fixture['device_serial'],
            'unit_id' => $fixture['unit_id'],
            'events' => [
                [
                    'local_event_id' => '11111111-1111-1111-1111-111111111116',
                    'collaborator_id' => 1363,
                    'punched_at_local' => '2026-02-18T08:06:00',
                    'timezone' => 'America/Mexico_City',
                    'punched_at_utc' => '2026-02-18T14:06:00Z',
                    'source' => 'FINGERPRINT',
                    'type_inout' => 'INOUT',
                ],
            ],
        ];

        $response = $this->signedJsonRequest(
            'POST',
            '/api/onprem/attendances',
            $payload,
            $fixture['device_serial'],
            $fixture['secret'],
        );

        $response->assertOk()
            ->assertJsonPath('received.0.status', 'STORED');

        $this->assertDatabaseHas('attendance_logs', [
            'local_id' => '11111111-1111-1111-1111-111111111116',
            'employee_id' => $employeeId,
            'fortia_employee_id' => 1363,
        ]);
    }
}
