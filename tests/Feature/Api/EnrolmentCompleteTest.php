<?php

namespace Tests\Feature\Api;

use App\Models\EmployeeFingerprint;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EnrolmentCompleteTest extends TestCase
{
    private const URI = '/api/FortiaPrimeApi.Opensync/api/v2/enrolments/complete';

    private bool $createdLocationsTable = false;
    private bool $createdClocksTable = false;
    private bool $createdEmployeesTable = false;
    private bool $createdEmployeeFingerprintsTable = false;
    private bool $createdEnrolmentAuditsTable = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
        $this->ensureSchema();
        $this->cleanData();
    }

    protected function tearDown(): void
    {
        if ($this->createdEnrolmentAuditsTable && Schema::hasTable('enrolment_audits')) {
            Schema::drop('enrolment_audits');
        }
        if ($this->createdEmployeeFingerprintsTable && Schema::hasTable('employee_fingerprints')) {
            Schema::drop('employee_fingerprints');
        }
        if ($this->createdEmployeesTable && Schema::hasTable('employees')) {
            Schema::drop('employees');
        }
        if ($this->createdClocksTable && Schema::hasTable('clocks')) {
            Schema::drop('clocks');
        }
        if ($this->createdLocationsTable && Schema::hasTable('locations')) {
            Schema::drop('locations');
        }

        parent::tearDown();
    }

    public function test_complete_creates_new_enrolment_and_audit(): void
    {
        $locationId = $this->createLocation();
        $clockId = $this->createClock($locationId);
        $employeeId = $this->createEmployee(['fortia_employee_id' => 800001]);

        $response = $this->postJson(self::URI, [
            'employee_id' => $employeeId,
            'clock_id' => $clockId,
            'enrolment_type' => 'FINGERPRINT',
            'template_vendor_id' => 'TPL-001',
            'template_b64' => base64_encode('template'),
            'template_format' => 'zkteco-v1',
            'device_serial' => 'DEVICE-123',
            'performed_at' => '2026-02-03T12:00:00Z',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('action', 'CREATED')
            ->assertJsonPath('vendor_template_id', 'TPL-001');

        $this->assertDatabaseHas('employee_fingerprints', [
            'employee_id' => $employeeId,
            'clock_id' => $clockId,
            'vendor_template_id' => 'TPL-001',
            'status' => 'enrolled',
        ]);

        $this->assertDatabaseHas('enrolment_audits', [
            'employee_id' => $employeeId,
            'clock_id' => $clockId,
            'vendor_template_id' => 'TPL-001',
            'status' => 'SENT',
        ]);

        $audit = DB::table('enrolment_audits')->where('vendor_template_id', 'TPL-001')->first();
        $metadata = json_decode((string) $audit->metadata, true);

        $this->assertSame($clockId, $metadata['resolved_clock_id'] ?? null);
        $this->assertSame($locationId, $metadata['resolved_unit_id'] ?? null);
    }

    public function test_complete_is_idempotent_for_same_employee_and_vendor_template(): void
    {
        $locationId = $this->createLocation();
        $clockId = $this->createClock($locationId);
        $employeeId = $this->createEmployee([
            'fortia_employee_id' => 800002,
            'has_fingerprint' => 1,
        ]);

        EmployeeFingerprint::query()->create([
            'employee_id' => $employeeId,
            'clock_id' => $clockId,
            'vendor_template_id' => 'TPL-REUSED',
            'template_b64' => base64_encode('existing-template'),
            'template_format' => 'zkteco-v1',
            'status' => 'enrolled',
            'enrolled_at' => now(),
            'performed_at' => now(),
        ]);

        $response = $this->postJson(self::URI, [
            'employee_id' => $employeeId,
            'clock_id' => $clockId,
            'enrolment_type' => 'FINGERPRINT',
            'template_vendor_id' => 'TPL-REUSED',
            'performed_at' => '2026-02-03T12:00:00Z',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('action', 'ALREADY')
            ->assertJsonPath('message', 'Already enrolled');

        $this->assertSame(1, EmployeeFingerprint::query()->count());
        $this->assertDatabaseHas('enrolment_audits', [
            'employee_id' => $employeeId,
            'vendor_template_id' => 'TPL-REUSED',
            'status' => 'DUPLICATE',
        ]);
    }

    public function test_complete_returns_conflict_when_template_belongs_to_another_employee(): void
    {
        $locationId = $this->createLocation();
        $clockId = $this->createClock($locationId);
        $employeeOne = $this->createEmployee([
            'fortia_employee_id' => 800003,
            'has_fingerprint' => 1,
        ]);
        $employeeTwo = $this->createEmployee(['fortia_employee_id' => 800004]);

        EmployeeFingerprint::query()->create([
            'employee_id' => $employeeOne,
            'clock_id' => $clockId,
            'vendor_template_id' => 'TPL-CONFLICT',
            'status' => 'enrolled',
            'enrolled_at' => now(),
            'performed_at' => now(),
        ]);

        $response = $this->postJson(self::URI, [
            'employee_id' => $employeeTwo,
            'clock_id' => $clockId,
            'enrolment_type' => 'FINGERPRINT',
            'template_vendor_id' => 'TPL-CONFLICT',
            'performed_at' => '2026-02-03T12:00:00Z',
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('action', 'CONFLICT');

        $this->assertDatabaseHas('enrolment_audits', [
            'employee_id' => $employeeTwo,
            'vendor_template_id' => 'TPL-CONFLICT',
            'status' => 'CONFLICT',
        ]);
    }

    public function test_complete_requires_template_b64_for_new_enrolment(): void
    {
        $locationId = $this->createLocation();
        $clockId = $this->createClock($locationId);
        $employeeId = $this->createEmployee(['fortia_employee_id' => 800005]);

        $response = $this->postJson(self::URI, [
            'employee_id' => $employeeId,
            'clock_id' => $clockId,
            'enrolment_type' => 'FINGERPRINT',
            'template_vendor_id' => 'TPL-NEW-NO-B64',
            'performed_at' => '2026-02-03T12:00:00Z',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.template_b64.0', 'The template_b64 field is required for new enrolments.');

        $this->assertDatabaseHas('enrolment_audits', [
            'employee_id' => $employeeId,
            'vendor_template_id' => 'TPL-NEW-NO-B64',
            'status' => 'REJECTED',
        ]);
    }

    public function test_complete_rejects_invalid_base64_for_new_fingerprint_enrolment(): void
    {
        $locationId = $this->createLocation(['name' => 'Unit Invalid B64']);
        $clockId = $this->createClock($locationId);
        $employeeId = $this->createEmployee(['fortia_employee_id' => 800051]);

        $response = $this->postJson(self::URI, [
            'employee_id' => $employeeId,
            'clock_id' => $clockId,
            'enrolment_type' => 'FINGERPRINT',
            'template_vendor_id' => 'TPL-INVALID-B64',
            'template_b64' => 'not-base64@@@',
            'template_format' => 'zkteco-v1',
            'performed_at' => '2026-02-03T12:00:00Z',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.template_b64.0', 'The template_b64 field must be valid Base64.');

        $this->assertDatabaseMissing('employee_fingerprints', [
            'employee_id' => $employeeId,
            'vendor_template_id' => 'TPL-INVALID-B64',
        ]);
    }

    public function test_complete_rejects_invalid_template_format_for_new_fingerprint_enrolment(): void
    {
        $locationId = $this->createLocation(['name' => 'Unit Invalid Format']);
        $clockId = $this->createClock($locationId);
        $employeeId = $this->createEmployee(['fortia_employee_id' => 800052]);

        $response = $this->postJson(self::URI, [
            'employee_id' => $employeeId,
            'clock_id' => $clockId,
            'enrolment_type' => 'FINGERPRINT',
            'template_vendor_id' => 'TPL-INVALID-FORMAT',
            'template_b64' => base64_encode('template'),
            'template_format' => 'unsupported-v9',
            'performed_at' => '2026-02-03T12:00:00Z',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.template_format.0', 'The selected template_format is invalid for fingerprint enrolments.');

        $this->assertDatabaseMissing('employee_fingerprints', [
            'employee_id' => $employeeId,
            'vendor_template_id' => 'TPL-INVALID-FORMAT',
        ]);
    }

    public function test_complete_rejects_empty_template_b64_for_new_fingerprint_enrolment(): void
    {
        $locationId = $this->createLocation(['name' => 'Unit Empty Template']);
        $clockId = $this->createClock($locationId);
        $employeeId = $this->createEmployee(['fortia_employee_id' => 800053]);

        $response = $this->postJson(self::URI, [
            'employee_id' => $employeeId,
            'clock_id' => $clockId,
            'enrolment_type' => 'FINGERPRINT',
            'template_vendor_id' => 'TPL-EMPTY-B64',
            'template_b64' => '   ',
            'template_format' => 'DPFP_PROPRIETARY',
            'performed_at' => '2026-02-03T12:00:00Z',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.template_b64.0', 'The template_b64 field is required for new enrolments.');

        $this->assertDatabaseMissing('employee_fingerprints', [
            'employee_id' => $employeeId,
            'vendor_template_id' => 'TPL-EMPTY-B64',
        ]);
    }

    public function test_face_enrolment_updates_face_summary_without_marking_fingerprint(): void
    {
        $locationId = $this->createLocation(['name' => 'Unit Face']);
        $clockId = $this->createClock($locationId, ['clock_name' => 'Clock Face']);
        $employeeId = $this->createEmployee([
            'fortia_employee_id' => 800006,
            'has_face_enrollment' => 0,
            'face_enabled' => 0,
            'face_status' => 'none',
        ]);

        $response = $this->postJson(self::URI, [
            'employee_id' => $employeeId,
            'clock_id' => $clockId,
            'enrolment_type' => 'FACE',
            'template_vendor_id' => 'FACE-001',
            'template_b64' => base64_encode('face-template'),
            'template_format' => 'FACE_EMBEDDING_V1',
            'device_serial' => 'FACE-DEVICE-123',
            'samples_count' => 4,
            'quality_score' => 91,
            'template_version' => 'FACE_EMBEDDING_V1',
            'metadata' => [
                'capture_source' => 'enroller-app',
            ],
            'performed_at' => '2026-02-03T13:00:00Z',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('action', 'CREATED');

        $this->assertDatabaseHas('employee_fingerprints', [
            'employee_id' => $employeeId,
            'vendor_template_id' => 'FACE-001',
            'enrolment_type' => 'FACE',
            'status' => 'enrolled',
        ]);

        $this->assertDatabaseHas('employees', [
            'id' => $employeeId,
            'has_fingerprint' => 0,
            'has_face_enrollment' => 1,
            'face_status' => 'enrolled',
            'face_enabled' => 1,
            'face_samples_count' => 4,
            'face_template_version' => 'FACE_EMBEDDING_V1',
            'face_quality_score' => 91,
        ]);
    }

    public function test_complete_accepts_employee_code_and_unit_id_for_winforms_payload(): void
    {
        $locationId = $this->createLocation([
            'name' => 'Centro Logistico',
            'fortia_location_id' => 501,
            'code' => '501',
        ]);
        $clockId = $this->createClock($locationId, [
            'clock_name' => 'Clock Centro Logistico',
        ]);
        $employeeId = $this->createEmployee(['fortia_employee_id' => 9101]);

        $response = $this->postJson(self::URI, [
            'fortia_employee_id' => '9101',
            'unit_id' => $locationId,
            'enrolment_type' => 'FINGERPRINT',
            'template_vendor_id' => 'WINADMIN-FP-9101',
            'template_b64' => base64_encode('dpfp-template'),
            'template_format' => 'DPFP.Template.Bytes',
            'performed_at' => '2026-04-01T17:26:00Z',
            'metadata' => [
                'capture_source' => 'winforms-admin',
                'capture_flow' => 'legacy_dpfp',
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('action', 'CREATED')
            ->assertJsonPath('employee_id', $employeeId)
            ->assertJsonPath('fortia_employee_id', '9101')
            ->assertJsonPath('clock_id', $clockId)
            ->assertJsonPath('unit_id', $locationId)
            ->assertJsonPath('has_fingerprint', true)
            ->assertJsonPath('fingerprint_status', 'enrolled');

        $this->assertDatabaseHas('employee_fingerprints', [
            'employee_id' => $employeeId,
            'clock_id' => $clockId,
            'vendor_template_id' => 'WINADMIN-FP-9101',
            'template_format' => 'DPFP_PROPRIETARY',
            'status' => 'enrolled',
        ]);
    }

    public function test_complete_accepts_clock_id_with_fortia_unit_id(): void
    {
        $locationId = $this->createLocation([
            'fortia_location_id' => 692,
            'code' => 'CLN-692',
            'name' => 'Clínica Misiones',
        ]);
        $clockId = $this->createClock($locationId, [
            'serial_number' => '692',
            'clock_name' => 'Clock 692',
        ]);
        $employeeId = $this->createEmployee(['fortia_employee_id' => 12015]);

        $response = $this->postJson(self::URI, [
            'employee_id' => $employeeId,
            'clock_id' => $clockId,
            'unit_id' => 692,
            'enrolment_type' => 'FINGERPRINT',
            'template_vendor_id' => 'TPL-FORTIA-UNIT',
            'template_b64' => base64_encode('template'),
            'template_format' => 'DPFP.Template.Bytes',
            'performed_at' => '2026-06-01T12:00:00Z',
        ]);

        $response->assertOk()
            ->assertJsonPath('clock_id', $clockId)
            ->assertJsonPath('unit_id', $locationId);
    }

    public function test_complete_accepts_clock_id_with_code_unit_id(): void
    {
        $locationId = $this->createLocation([
            'fortia_location_id' => 9001,
            'code' => '7001',
            'name' => 'Unidad Código',
        ]);
        $clockId = $this->createClock($locationId, [
            'serial_number' => '7001',
            'clock_name' => 'Clock Code',
        ]);
        $employeeId = $this->createEmployee(['fortia_employee_id' => 12016]);

        $response = $this->postJson(self::URI, [
            'employee_id' => $employeeId,
            'clock_id' => $clockId,
            'unit_id' => 7001,
            'enrolment_type' => 'FINGERPRINT',
            'template_vendor_id' => 'TPL-CODE-UNIT',
            'template_b64' => base64_encode('template'),
            'template_format' => 'DPFP.Template.Bytes',
            'performed_at' => '2026-06-01T12:10:00Z',
        ]);

        $response->assertOk()
            ->assertJsonPath('clock_id', $clockId)
            ->assertJsonPath('unit_id', $locationId);
    }

    public function test_complete_accepts_device_serial_with_fortia_unit_id(): void
    {
        $locationId = $this->createLocation([
            'fortia_location_id' => 692,
            'code' => '692',
            'name' => 'Clínica Médica Tu Nueva Historia Misiones',
        ]);
        $clockId = $this->createClock($locationId, [
            'serial_number' => '692',
            'clock_name' => 'Clínica Médica Tu Nueva Historia Gobernadores',
        ]);
        $employeeId = $this->createEmployee(['fortia_employee_id' => 12015]);

        $response = $this->postJson(self::URI, [
            'employee_id' => $employeeId,
            'device_serial' => '692',
            'unit_id' => 692,
            'enrolment_type' => 'FINGERPRINT',
            'template_vendor_id' => 'WINADMIN-FP-12015',
            'template_b64' => base64_encode('template-692'),
            'template_format' => 'DPFP.Template.Bytes',
            'performed_at' => '2026-06-01T12:20:00Z',
        ]);

        $response->assertOk()
            ->assertJsonPath('clock_id', $clockId)
            ->assertJsonPath('unit_id', $locationId);
    }

    public function test_complete_accepts_device_serial_with_internal_unit_id_even_when_fortia_match_is_ambiguous(): void
    {
        $locationId = $this->createLocation([
            'fortia_location_id' => 100006,
            'code' => '100006',
            'name' => 'Hotel Bali Hai',
        ]);
        $clockId = $this->createClock($locationId, [
            'serial_number' => '100006',
            'clock_name' => 'Clock Hotel Bali Hai',
        ]);
        $this->createLocation([
            'fortia_location_id' => $locationId,
            'code' => '253',
            'name' => 'Location Fortia Ambigua',
        ]);
        $employeeId = $this->createEmployee(['fortia_employee_id' => 12022]);

        $response = $this->postJson(self::URI, [
            'employee_id' => $employeeId,
            'device_serial' => '100006',
            'unit_id' => $locationId,
            'enrolment_type' => 'FINGERPRINT',
            'template_vendor_id' => 'TPL-INTERNAL-UNIT-SERIAL',
            'template_b64' => base64_encode('template-100006'),
            'template_format' => 'DPFP.Template.Bytes',
            'performed_at' => '2026-06-10T10:00:00Z',
        ]);

        $response->assertOk()
            ->assertJsonPath('clock_id', $clockId)
            ->assertJsonPath('unit_id', $locationId);
    }

    public function test_complete_accepts_device_serial_without_unit_id(): void
    {
        $locationId = $this->createLocation([
            'fortia_location_id' => 720,
            'code' => '720',
        ]);
        $clockId = $this->createClock($locationId, [
            'serial_number' => 'SER-720',
            'clock_name' => 'Clock 720',
        ]);
        $employeeId = $this->createEmployee(['fortia_employee_id' => 12017]);

        $response = $this->postJson(self::URI, [
            'employee_id' => $employeeId,
            'device_serial' => 'SER-720',
            'enrolment_type' => 'FINGERPRINT',
            'template_vendor_id' => 'TPL-SERIAL-ONLY',
            'template_b64' => base64_encode('template-serial'),
            'template_format' => 'DPFP.Template.Bytes',
            'performed_at' => '2026-06-01T12:25:00Z',
        ]);

        $response->assertOk()
            ->assertJsonPath('clock_id', $clockId)
            ->assertJsonPath('unit_id', $locationId);
    }

    public function test_complete_rejects_when_clock_id_and_device_serial_point_to_different_clocks(): void
    {
        $locationOne = $this->createLocation(['name' => 'Unit One']);
        $locationTwo = $this->createLocation(['name' => 'Unit Two']);
        $clockId = $this->createClock($locationOne, ['serial_number' => 'CLOCK-ONE']);
        $this->createClock($locationTwo, ['serial_number' => 'CLOCK-TWO']);
        $employeeId = $this->createEmployee(['fortia_employee_id' => 12018]);

        $response = $this->postJson(self::URI, [
            'employee_id' => $employeeId,
            'clock_id' => $clockId,
            'device_serial' => 'CLOCK-TWO',
            'enrolment_type' => 'FINGERPRINT',
            'template_vendor_id' => 'TPL-CLOCK-MISMATCH',
            'template_b64' => base64_encode('template'),
            'template_format' => 'DPFP.Template.Bytes',
            'performed_at' => '2026-06-01T12:30:00Z',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'clock_id='.$clockId.' and device_serial=CLOCK-TWO do not correspond to the same clock.');
    }

    public function test_complete_rejects_when_clock_location_differs_from_resolved_unit(): void
    {
        $clockLocationId = $this->createLocation([
            'fortia_location_id' => 801,
            'code' => '801',
            'name' => 'Clock Unit',
        ]);
        $otherLocationId = $this->createLocation([
            'fortia_location_id' => 999,
            'code' => '999',
            'name' => 'Other Unit',
        ]);
        $clockId = $this->createClock($clockLocationId, [
            'serial_number' => 'SER-MISMATCH',
        ]);
        $employeeId = $this->createEmployee(['fortia_employee_id' => 12019]);

        $response = $this->postJson(self::URI, [
            'employee_id' => $employeeId,
            'clock_id' => $clockId,
            'unit_id' => 999,
            'enrolment_type' => 'FINGERPRINT',
            'template_vendor_id' => 'TPL-UNIT-MISMATCH',
            'template_b64' => base64_encode('template'),
            'template_format' => 'DPFP.Template.Bytes',
            'performed_at' => '2026-06-01T12:35:00Z',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath(
                'message',
                'El reloj con serie SER-MISMATCH pertenece a location_id='.$clockLocationId.' / fortia_location_id=801 / code=801, pero la solicitud envió unit_id=999 y se resolvió como location_id='.$otherLocationId.'.'
            );
    }

    public function test_complete_rejects_ambiguous_unit_id(): void
    {
        DB::table('locations')->insert([
            'id' => 700,
            'name' => 'Location Internal 700',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('locations')->insert([
            'name' => 'Location Fortia 700',
            'fortia_location_id' => 700,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employeeId = $this->createEmployee(['fortia_employee_id' => 12020]);

        $response = $this->postJson(self::URI, [
            'employee_id' => $employeeId,
            'unit_id' => 700,
            'enrolment_type' => 'FINGERPRINT',
            'template_vendor_id' => 'TPL-AMBIGUOUS-UNIT',
            'template_b64' => base64_encode('template'),
            'template_format' => 'DPFP.Template.Bytes',
            'performed_at' => '2026-06-01T12:40:00Z',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'The provided unit_id=700 is ambiguous across multiple locations.');
    }

    public function test_complete_rejects_unknown_device_serial(): void
    {
        $locationId = $this->createLocation(['fortia_location_id' => 950, 'code' => '950']);
        $employeeId = $this->createEmployee(['fortia_employee_id' => 12021]);

        $response = $this->postJson(self::URI, [
            'employee_id' => $employeeId,
            'device_serial' => 'UNKNOWN-SERIAL',
            'unit_id' => 950,
            'enrolment_type' => 'FINGERPRINT',
            'template_vendor_id' => 'TPL-UNKNOWN-SERIAL',
            'template_b64' => base64_encode('template'),
            'template_format' => 'DPFP.Template.Bytes',
            'performed_at' => '2026-06-01T12:45:00Z',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'The provided device_serial UNKNOWN-SERIAL could not be resolved to a registered clock.');

        $this->assertDatabaseMissing('employee_fingerprints', [
            'employee_id' => $employeeId,
            'vendor_template_id' => 'TPL-UNKNOWN-SERIAL',
        ]);

        $this->assertDatabaseHas('enrolment_audits', [
            'employee_id' => $employeeId,
            'vendor_template_id' => 'TPL-UNKNOWN-SERIAL',
            'status' => 'REJECTED',
        ]);
    }

    public function test_complete_accepts_unit_id_matching_employee_base_location_even_without_locations_row(): void
    {
        $employeeId = $this->createEmployee([
            'fortia_employee_id' => 9103,
            'base_location_id' => 102,
        ]);

        $response = $this->postJson(self::URI, [
            'fortia_employee_id' => '9103',
            'unit_id' => 102,
            'enrolment_type' => 'FINGERPRINT',
            'template_vendor_id' => 'WINADMIN-FP-9103',
            'template_b64' => base64_encode('template-prod-like'),
            'template_format' => 'DPFP.Template.Bytes',
            'performed_at' => '2026-04-01T19:15:00Z',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('action', 'CREATED')
            ->assertJsonPath('employee_id', $employeeId)
            ->assertJsonPath('unit_id', 102)
            ->assertJsonPath('has_fingerprint', true);

        $this->assertDatabaseHas('employee_fingerprints', [
            'employee_id' => $employeeId,
            'vendor_template_id' => 'WINADMIN-FP-9103',
            'template_format' => 'DPFP_PROPRIETARY',
            'status' => 'enrolled',
        ]);
    }

    private function createLocation(array $overrides = []): int
    {
        return DB::table('locations')->insertGetId(array_merge([
            'name' => 'Unit A',
            'fortia_location_id' => null,
            'code' => null,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function createClock(int $locationId, array $overrides = []): int
    {
        return DB::table('clocks')->insertGetId(array_merge([
            'location_id' => $locationId,
            'clock_name' => 'Clock 1',
            'serial_number' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function createEmployee(array $overrides = []): int
    {
        return DB::table('employees')->insertGetId(array_merge([
            'fortia_employee_id' => null,
            'status' => 'A',
            'has_fingerprint' => 0,
            'base_location_id' => null,
            'has_face_enrollment' => 0,
            'face_status' => 'none',
            'face_samples_count' => 0,
            'face_template_version' => null,
            'face_updated_at' => null,
            'face_enabled' => 0,
            'face_quality_score' => null,
            'face_meta' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function ensureSchema(): void
    {
        if (! Schema::hasTable('locations')) {
            Schema::create('locations', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('fortia_location_id')->nullable();
                $table->string('code', 30)->nullable();
                $table->string('name')->nullable();
                $table->tinyInteger('status')->default(1);
                $table->timestamps();
            });
            $this->createdLocationsTable = true;
        }

        if (! Schema::hasTable('clocks')) {
            Schema::create('clocks', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('location_id')->nullable();
                $table->string('clock_name')->default('Clock');
                $table->string('serial_number')->nullable();
                $table->timestamps();
            });
            $this->createdClocksTable = true;
        }

        if (! Schema::hasTable('employees')) {
            Schema::create('employees', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('fortia_employee_id')->nullable();
                $table->string('status', 20)->default('A');
                $table->boolean('has_fingerprint')->default(false);
                $table->timestamps();
            });
            $this->createdEmployeesTable = true;
        }

        Schema::table('employees', function (Blueprint $table): void {
            if (! Schema::hasColumn('employees', 'base_location_id')) {
                $table->unsignedBigInteger('base_location_id')->nullable();
            }
            if (! Schema::hasColumn('employees', 'has_face_enrollment')) {
                $table->boolean('has_face_enrollment')->default(false);
            }
            if (! Schema::hasColumn('employees', 'face_status')) {
                $table->string('face_status', 30)->default('none');
            }
            if (! Schema::hasColumn('employees', 'face_samples_count')) {
                $table->unsignedInteger('face_samples_count')->default(0);
            }
            if (! Schema::hasColumn('employees', 'face_template_version')) {
                $table->string('face_template_version', 80)->nullable();
            }
            if (! Schema::hasColumn('employees', 'face_updated_at')) {
                $table->dateTime('face_updated_at')->nullable();
            }
            if (! Schema::hasColumn('employees', 'face_enabled')) {
                $table->boolean('face_enabled')->default(false);
            }
            if (! Schema::hasColumn('employees', 'face_quality_score')) {
                $table->unsignedSmallInteger('face_quality_score')->nullable();
            }
            if (! Schema::hasColumn('employees', 'face_meta')) {
                $table->json('face_meta')->nullable();
            }
        });

        if (! Schema::hasTable('employee_fingerprints')) {
            Schema::create('employee_fingerprints', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('employee_id');
                $table->unsignedBigInteger('clock_id')->nullable();
                $table->string('vendor_template_id', 191)->nullable();
                $table->string('template_vendor', 100)->nullable();
                $table->string('template_source', 100)->nullable();
                $table->longText('template_b64')->nullable();
                $table->string('template_format', 40)->nullable();
                $table->string('enrolment_type', 50)->nullable();
                $table->string('device_serial', 191)->nullable();
                $table->string('status', 30)->default('enrolled');
                $table->dateTime('enrolled_at')->nullable();
                $table->dateTime('performed_at')->nullable();
                $table->dateTime('deleted_at')->nullable();
                $table->timestamps();

                $table->index('vendor_template_id', 'employee_fingerprints_vendor_template_id_idx');
                $table->unique(
                    ['employee_id', 'vendor_template_id'],
                    'employee_fingerprints_employee_vendor_unique'
                );
            });
            $this->createdEmployeeFingerprintsTable = true;
        }

        if (! Schema::hasTable('enrolment_audits')) {
            Schema::create('enrolment_audits', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('employee_id')->nullable();
                $table->unsignedBigInteger('clock_id')->nullable();
                $table->string('enrolment_type', 50)->nullable();
                $table->string('vendor_template_id', 191)->nullable();
                $table->string('device_serial', 191)->nullable();
                $table->dateTime('performed_at')->nullable();
                $table->string('status', 20);
                $table->string('reason', 255)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
            $this->createdEnrolmentAuditsTable = true;
        }
    }

    private function cleanData(): void
    {
        DB::table('enrolment_audits')->delete();
        EmployeeFingerprint::query()->delete();
        DB::table('employees')->delete();
        DB::table('clocks')->delete();
        DB::table('locations')->delete();
    }
}
