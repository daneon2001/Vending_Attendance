<?php

namespace Tests\Feature\Vending;

use App\Enums\Vending\GeofenceStatus;
use App\Enums\Vending\SybiVendingSyncStatus;
use App\Enums\Vending\VendingCatalogSource;
use App\Enums\Vending\VendingMachineStatus;
use App\Models\MachineGeofence;
use App\Models\SybiVendingSyncRun;
use App\Models\VendingMachine;
use App\Services\Vending\SybiVendingSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SybiVendingSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sybi.vending.url', 'https://sybi.invalid/v1/public/sucursales-vending');
        config()->set('sybi.vending.token', 'test-only-random-value');
    }

    public function test_sync_creates_draft_machine_with_exact_mapping_and_safe_defaults(): void
    {
        $summary = $this->sync([$this->record()]);
        $machine = VendingMachine::query()->where('sybi_id', '501')->firstOrFail();

        $this->assertSame(1, $summary['created']);
        $this->assertSame('VM-SYBI-501', $machine->machine_code);
        $this->assertSame('Vending Centro', $machine->name);
        $this->assertSame('Av. Reforma 100', $machine->address_line);
        $this->assertSame('Centro', $machine->neighborhood);
        $this->assertSame('06000', $machine->postal_code);
        $this->assertSame(10, $machine->sybi_city_id);
        $this->assertSame(9, $machine->sybi_state_id);
        $this->assertSame('Av. Reforma 100, Centro, 06000', $machine->sybi_full_address);
        $this->assertSame(VendingCatalogSource::SYBI, $machine->source);
        $this->assertSame(VendingMachineStatus::DRAFT, $machine->status);
        $this->assertSame(SybiVendingSyncStatus::SYNCED, $machine->sybi_sync_status);
        $this->assertFalse($machine->coordinates_verified);
        $this->assertNotNull($machine->sybi_last_seen_at);
        $this->assertDatabaseHas('sybi_vending_sync_runs', [
            'status' => 'COMPLETED',
            'received' => 1,
            'source_candidates' => 1,
            'source_created' => 1,
            'operational_ready' => 1,
            'operational_created' => 1,
            'created' => 1,
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'sybi.vending.sync.started']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'sybi.vending.sync.completed']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'vending_machine.sybi_created', 'auditable_id' => $machine->id]);
    }

    public function test_identical_second_sync_is_idempotent_and_nullable_address_fields_are_supported(): void
    {
        $record = $this->record([
            'nombre_sucursal' => null,
            'ubicacion' => [
                'calle' => null,
                'colonia' => null,
                'codigo_postal' => null,
                'id_ciudad' => null,
                'id_estado' => null,
                'direccion_completa' => null,
            ],
        ]);
        $this->fakeResponses([[$record], [$record]]);
        $service = app(SybiVendingSyncService::class);
        $service->sync();
        $summary = $service->sync();

        $this->assertSame(1, $summary['unchanged']);
        $this->assertSame(1, VendingMachine::query()->where('sybi_id', '501')->count());
        $this->assertNull(VendingMachine::query()->where('sybi_id', '501')->value('address_line'));
    }

    public function test_existing_sybi_machine_is_updated_but_local_operational_fields_are_preserved(): void
    {
        $this->fakeResponses([
            [$this->record()],
            [$this->record(['nombre_sucursal' => 'Nombre actualizado'])],
        ]);
        $service = app(SybiVendingSyncService::class);
        $service->sync();
        $machine = VendingMachine::query()->where('sybi_id', '501')->firstOrFail();
        $machine->forceFill([
            'status' => VendingMachineStatus::MAINTENANCE,
            'timezone' => 'America/Tijuana',
            'operational_code' => 'OPS-LOCAL',
        ])->save();

        $summary = $service->sync();
        $machine->refresh();

        $this->assertSame(1, $summary['updated']);
        $this->assertSame('Nombre actualizado', $machine->name);
        $this->assertSame(VendingMachineStatus::MAINTENANCE, $machine->status);
        $this->assertSame('America/Tijuana', $machine->timezone);
        $this->assertSame('OPS-LOCAL', $machine->operational_code);
    }

    public function test_duplicate_identity_code_collisions_and_invalid_records_are_not_written(): void
    {
        VendingMachine::query()->create([
            'machine_code' => 'TAKEN-CODE',
            'timezone' => 'America/Mexico_City',
            'status' => VendingMachineStatus::DRAFT,
        ]);

        $summary = $this->sync([
            $this->record(),
            $this->record(['identificador_vending' => 'VM-DUPLICATE-ID']),
            $this->record(['id_sucursal' => 502, 'identificador_vending' => 'TAKEN-CODE']),
            $this->record(['id_sucursal' => 503, 'identificador_vending' => 'VM-BAD-LAT', 'latitud' => 91]),
            $this->record(['id_sucursal' => 504, 'identificador_vending' => 'VM-ZERO', 'latitud' => 0, 'longitud' => 0]),
        ]);

        $this->assertSame(1, $summary['conflicts']);
        $this->assertSame(1, $summary['rejected']);
        $this->assertSame(2, $summary['operational_incomplete']);
        $this->assertSame(4, \App\Models\SybiVendingSourceRecord::query()->count());
        $this->assertSame(0, $summary['created']);
        $this->assertSame(1, VendingMachine::query()->count());
    }

    public function test_duplicate_machine_codes_in_source_are_reported_as_conflicts(): void
    {
        $summary = $this->sync([
            $this->record(['id_sucursal' => 601, 'identificador_vending' => 'VM-SAME']),
            $this->record(['id_sucursal' => 602, 'identificador_vending' => 'VM-SAME']),
        ]);

        $this->assertSame(2, $summary['conflicts']);
        $this->assertSame(['DUPLICATE_VENDING_IDENTIFIER' => 2], $summary['conflict_reasons']);
        $this->assertSame(2, \App\Models\SybiVendingSourceRecord::query()->count());
        $this->assertSame(0, VendingMachine::query()->count());
    }

    public function test_coordinate_change_preserves_active_geofence_and_marks_review(): void
    {
        $this->fakeResponses([
            [$this->record()],
            [$this->record(['latitud' => 20.0001, 'longitud' => -100.0001])],
        ]);
        $service = app(SybiVendingSyncService::class);
        $service->sync();
        $machine = VendingMachine::query()->where('sybi_id', '501')->firstOrFail();
        $geofence = MachineGeofence::query()->create([
            'vending_machine_id' => $machine->id,
            'version' => 1,
            'center_latitude' => 19.4326,
            'center_longitude' => -99.1332,
            'radius_m' => 40,
            'minimum_acceptable_accuracy_m' => 25,
            'tolerance_m' => 5,
            'status' => GeofenceStatus::ACTIVE,
            'source' => 'MANUAL',
        ]);
        $beforeVersion = $machine->config_version;

        $summary = $service->sync();
        $machine->refresh();
        $geofence->refresh();

        $this->assertSame(1, $summary['updated']);
        $this->assertSame('19.4326000', $geofence->center_latitude);
        $this->assertSame('-99.1332000', $geofence->center_longitude);
        $this->assertSame(GeofenceStatus::ACTIVE, $geofence->status);
        $this->assertTrue($machine->geofence_review_required);
        $this->assertFalse($machine->coordinates_verified);
        $this->assertSame(SybiVendingSyncStatus::REVIEW_REQUIRED, $machine->sybi_sync_status);
        $this->assertGreaterThan($beforeVersion, $machine->config_version);
        $this->assertDatabaseHas('audit_logs', ['event' => 'geofence.review_required', 'auditable_id' => $machine->id]);
    }

    public function test_non_empty_response_marks_missing_sybi_records_without_deleting_and_never_touches_demo(): void
    {
        $this->fakeResponses([
            [$this->record(), $this->record(['id_sucursal' => 502, 'identificador_vending' => 'VM-SYBI-502'])],
            [$this->record()],
        ]);
        $service = app(SybiVendingSyncService::class);
        $service->sync();
        $demo = VendingMachine::query()->create([
            'machine_code' => 'VM-DEMO-001',
            'source' => VendingCatalogSource::DEMO,
            'timezone' => 'America/Mexico_City',
            'status' => VendingMachineStatus::ACTIVE,
        ]);

        $summary = $service->sync();

        $this->assertSame(1, $summary['missing']);
        $this->assertDatabaseHas('vending_machines', [
            'sybi_id' => '502',
            'sybi_sync_status' => SybiVendingSyncStatus::SOURCE_MISSING->value,
        ]);
        $this->assertSame(VendingCatalogSource::DEMO, $demo->fresh()->source);
        $this->assertSame(VendingMachineStatus::ACTIVE, $demo->fresh()->status);
        $this->assertSame(3, VendingMachine::query()->count());
    }

    public function test_empty_source_guard_does_not_mark_existing_catalog_missing(): void
    {
        $this->fakeResponses([[$this->record()], []]);
        $service = app(SybiVendingSyncService::class);
        $service->sync();

        $summary = $service->sync();

        $this->assertTrue($summary['empty_source_guarded']);
        $this->assertContains('SOURCE_EMPTY_UNEXPECTED', $summary['warnings']);
        $this->assertSame(SybiVendingSyncStatus::SYNCED, VendingMachine::query()->firstOrFail()->sybi_sync_status);
    }

    public function test_dry_run_reports_impact_without_writing_machines_runs_or_audit(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'total' => 1, 'data' => [$this->record()]])]);
        $auditBefore = \App\Models\AuditLog::query()->count();

        $summary = app(SybiVendingSyncService::class)->sync(true);

        $this->assertTrue($summary['dry_run']);
        $this->assertSame(1, $summary['created']);
        $this->assertSame(0, VendingMachine::query()->count());
        $this->assertSame(0, SybiVendingSyncRun::query()->count());
        $this->assertSame($auditBefore, \App\Models\AuditLog::query()->count());
    }

    public function test_reserved_demo_identifier_from_source_is_rejected(): void
    {
        $summary = $this->sync([$this->record(['identificador_vending' => 'VM-DEMO-999'])]);

        $this->assertSame(1, $summary['rejected']);
        $this->assertSame(['DEMO_IDENTIFIER_RESERVED' => 1], $summary['rejection_reasons']);
        $this->assertSame(0, VendingMachine::query()->count());
    }

    public function test_remote_failure_records_only_sanitized_run_and_audit_state(): void
    {
        Http::fake(['*' => Http::response(['provider_detail' => 'private'], 401)]);

        $this->artisan('sybi:sync-vending')
            ->expectsOutputToContain('AUTH_ERROR')
            ->assertFailed();

        $run = SybiVendingSyncRun::query()->firstOrFail();
        $this->assertSame('FAILED', $run->status->value);
        $this->assertSame('AUTH_ERROR', $run->error_code->value);
        $this->assertStringNotContainsString('private', (string) $run->error_message);
        $this->assertDatabaseHas('audit_logs', ['event' => 'sybi.vending.sync.failed', 'auditable_id' => $run->id]);
    }

    public function test_dry_run_command_prints_summary_without_payload_or_writes(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'total' => 1, 'data' => [$this->record()]])]);

        $this->artisan('sybi:sync-vending --dry-run')
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertSame(0, VendingMachine::query()->count());
        $this->assertSame(0, SybiVendingSyncRun::query()->count());
    }

    private function sync(array $records): array
    {
        Http::fake(['*' => Http::response(['ok' => true, 'total' => count($records), 'data' => $records])]);

        return app(SybiVendingSyncService::class)->sync();
    }

    private function fakeResponses(array $responses): void
    {
        $sequence = Http::sequence();
        foreach ($responses as $records) {
            $sequence->push(['ok' => true, 'total' => count($records), 'data' => $records]);
        }
        Http::fake(['*' => $sequence]);
    }

    private function record(array $overrides = []): array
    {
        return array_replace_recursive([
            'id_sucursal' => 501,
            'identificador_vending' => 'VM-SYBI-501',
            'nombre_sucursal' => 'Vending Centro',
            'ubicacion' => [
                'calle' => 'Av. Reforma 100',
                'colonia' => 'Centro',
                'codigo_postal' => '06000',
                'id_ciudad' => 10,
                'id_estado' => 9,
                'direccion_completa' => 'Av. Reforma 100, Centro, 06000',
            ],
            'latitud' => 19.4326,
            'longitud' => -99.1332,
        ], $overrides);
    }
}
