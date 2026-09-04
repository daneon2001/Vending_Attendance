<?php

namespace Tests\Feature\Vending;

use App\Enums\Vending\SybiVendingSourceStatus;
use App\Enums\Vending\SybiVendingValidationStatus;
use App\Enums\Vending\VendingCatalogSource;
use App\Enums\Vending\VendingMachineStatus;
use App\Models\SybiVendingSourceRecord;
use App\Models\VendingMachine;
use App\Services\Vending\SybiVendingSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SybiVendingSourceProjectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sybi.vending.url', 'https://sybi.invalid/v1/public/sucursales-vending');
        config()->set('sybi.vending.token', 'test-only-random-value');
    }

    public function test_all_identifiable_source_rows_are_persisted_while_only_ready_rows_are_promoted(): void
    {
        $summary = $this->sync([
            $this->record(['id_sucursal' => 821, 'identificador_vending' => '7']),
            $this->record(['id_sucursal' => 822, 'identificador_vending' => '9', 'latitud' => 0, 'longitud' => 0]),
            $this->record(['id_sucursal' => 818, 'identificador_vending' => '4', 'latitud' => 0, 'longitud' => 0]),
            $this->record(['id_sucursal' => 824, 'identificador_vending' => '9', 'latitud' => 0, 'longitud' => 0]),
            $this->record(['id_sucursal' => 825, 'identificador_vending' => '9', 'latitud' => 0, 'longitud' => 0]),
        ]);

        $this->assertSame(5, $summary['source_candidates']);
        $this->assertSame(5, $summary['source_created']);
        $this->assertSame(1, $summary['operational_ready']);
        $this->assertSame(4, $summary['operational_incomplete']);
        $this->assertSame(3, $summary['operational_conflicts']);
        $this->assertSame(5, SybiVendingSourceRecord::query()->count());
        $this->assertSame(1, VendingMachine::query()->count());
        $this->assertSame('7', VendingMachine::query()->firstOrFail()->machine_code);

        $conflicts = SybiVendingSourceRecord::query()->where('identificador_vending', '9')->get();
        $this->assertCount(3, $conflicts);
        $conflicts->each(function (SybiVendingSourceRecord $record): void {
            $this->assertSame(SybiVendingValidationStatus::IDENTIFIER_CONFLICT, $record->validation_status);
            $this->assertContains('ZERO_COORDINATES', $record->validation_codes);
            $this->assertContains('DUPLICATE_VENDING_IDENTIFIER', $record->validation_codes);
            $this->assertNull($record->promoted_vending_machine_id);
        });
    }

    public function test_zero_coordinates_are_source_evidence_but_never_operational_coordinates(): void
    {
        $summary = $this->sync([$this->record(['latitud' => 0, 'longitud' => 0])]);
        $source = SybiVendingSourceRecord::query()->firstOrFail();

        $this->assertSame(1, $summary['operational_incomplete']);
        $this->assertSame('0.0000000', $source->latitude);
        $this->assertSame('0.0000000', $source->longitude);
        $this->assertSame(SybiVendingValidationStatus::INCOMPLETE_LOCATION, $source->validation_status);
        $this->assertContains('ZERO_COORDINATES', $source->validation_codes);
        $this->assertNull($source->promoted_vending_machine_id);
        $this->assertSame(0, VendingMachine::query()->count());
    }

    public function test_missing_invalid_coordinates_and_missing_identifier_are_typed_without_losing_source_rows(): void
    {
        $summary = $this->sync([
            $this->record(['id_sucursal' => 601, 'identificador_vending' => 'VM-601', 'latitud' => null]),
            $this->record(['id_sucursal' => 602, 'identificador_vending' => 'VM-602', 'longitud' => 181]),
            $this->record(['id_sucursal' => 603, 'identificador_vending' => null]),
        ]);

        $this->assertSame(3, $summary['source_candidates']);
        $this->assertSame(2, $summary['operational_incomplete']);
        $this->assertSame(1, $summary['operational_invalid']);
        $this->assertContains('MISSING_COORDINATES', SybiVendingSourceRecord::query()->where('sybi_id', 601)->value('validation_codes'));
        $this->assertContains('INVALID_COORDINATES', SybiVendingSourceRecord::query()->where('sybi_id', 602)->value('validation_codes'));
        $this->assertContains('MISSING_VENDING_IDENTIFIER', SybiVendingSourceRecord::query()->where('sybi_id', 603)->value('validation_codes'));
        $this->assertSame(0, VendingMachine::query()->count());
    }

    public function test_identical_second_run_is_idempotent_for_source_and_operational_projection(): void
    {
        $record = $this->record();
        $this->fakeResponses([[$record], [$record]]);
        $service = app(SybiVendingSyncService::class);
        $service->sync();
        $summary = $service->sync();

        $this->assertSame(1, $summary['source_unchanged']);
        $this->assertSame(1, $summary['operational_unchanged']);
        $this->assertSame(1, SybiVendingSourceRecord::query()->count());
        $this->assertSame(1, VendingMachine::query()->count());
    }

    public function test_source_coordinate_correction_is_reevaluated_and_promoted(): void
    {
        $this->fakeResponses([
            [$this->record(['latitud' => 0, 'longitud' => 0])],
            [$this->record()],
        ]);
        $service = app(SybiVendingSyncService::class);
        $service->sync();
        $summary = $service->sync();

        $source = SybiVendingSourceRecord::query()->firstOrFail();
        $this->assertSame(1, $summary['source_updated']);
        $this->assertSame(1, $summary['operational_created']);
        $this->assertSame(SybiVendingValidationStatus::READY, $source->validation_status);
        $this->assertNotNull($source->promoted_vending_machine_id);
        $this->assertFalse($source->promotedVendingMachine->coordinates_verified);
    }

    public function test_source_missing_marks_projection_and_preserves_operational_machine(): void
    {
        $first = $this->record();
        $second = $this->record(['id_sucursal' => 502, 'identificador_vending' => 'VM-502']);
        $this->fakeResponses([[$first, $second], [$first]]);
        $service = app(SybiVendingSyncService::class);
        $service->sync();
        $summary = $service->sync();

        $missing = SybiVendingSourceRecord::query()->where('sybi_id', 502)->firstOrFail();
        $this->assertSame(1, $summary['operational_missing']);
        $this->assertSame(SybiVendingSourceStatus::SOURCE_MISSING, $missing->source_status);
        $this->assertSame(SybiVendingValidationStatus::SOURCE_MISSING, $missing->validation_status);
        $this->assertNotNull($missing->promotedVendingMachine);
        $this->assertSame(2, VendingMachine::query()->count());
    }

    public function test_existing_matching_operational_machine_is_linked_without_duplication(): void
    {
        $machine = VendingMachine::query()->create([
            'sybi_id' => '501',
            'machine_code' => 'VM-501',
            'timezone' => 'America/Mexico_City',
            'status' => VendingMachineStatus::DRAFT,
        ]);

        $summary = $this->sync([$this->record()]);

        $this->assertSame(1, $summary['operational_updated']);
        $this->assertSame(1, VendingMachine::query()->count());
        $this->assertSame($machine->id, SybiVendingSourceRecord::query()->firstOrFail()->promoted_vending_machine_id);
    }

    public function test_demo_catalog_is_untouched_and_demo_identifiers_are_not_projected(): void
    {
        $demo = VendingMachine::query()->create([
            'machine_code' => 'VM-DEMO-001',
            'source' => VendingCatalogSource::DEMO,
            'timezone' => 'America/Mexico_City',
            'status' => VendingMachineStatus::ACTIVE,
        ]);

        $summary = $this->sync([$this->record(['identificador_vending' => 'VM-DEMO-999'])]);

        $this->assertSame(1, $summary['source_invalid']);
        $this->assertSame(0, SybiVendingSourceRecord::query()->count());
        $this->assertSame(VendingCatalogSource::DEMO, $demo->fresh()->source);
        $this->assertSame(VendingMachineStatus::ACTIVE, $demo->fresh()->status);
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
            'identificador_vending' => 'VM-501',
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
