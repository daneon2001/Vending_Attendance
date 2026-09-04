<?php

namespace Tests\Feature\Console;

use App\Models\AuditLog;
use App\Models\SybiVendingSyncRun;
use App\Models\VendingMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SybiVendingRejectionDiagnosticTest extends TestCase
{
    use RefreshDatabase;

    private string $fakeToken = 'diagnostic-test-value-never-print';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sybi.vending.url', 'https://sybi.invalid/v1/public/sucursales-vending');
        config()->set('sybi.vending.token', $this->fakeToken);
    }

    public function test_show_rejections_is_recognized_and_prints_only_sanitized_fields(): void
    {
        $sensitivePayloadMarker = 'FULL-PAYLOAD-MUST-NOT-APPEAR';
        Http::fake(['*' => Http::response([
            'ok' => true,
            'total' => 4,
            'data' => [
                $this->record(['id_sucursal' => null, 'private_data' => $sensitivePayloadMarker]),
                $this->record(['id_sucursal' => 702, 'identificador_vending' => 7]),
                $this->record(['id_sucursal' => 703, 'identificador_vending' => 'VM-703', 'latitud' => null]),
                $this->record(['id_sucursal' => 704, 'identificador_vending' => "VM-704\e[31m", 'latitud' => 0, 'longitud' => 0]),
            ],
        ])]);

        $this->artisan('sybi:sync-vending --dry-run --show-rejections')
            ->expectsOutputToContain('Rejected records')
            ->expectsOutputToContain('MISSING_SYBI_ID')
            ->expectsOutputToContain('INVALID_VENDING_IDENTIFIER')
            ->expectsOutputToContain('MISSING_COORDINATES')
            ->expectsOutputToContain('ZERO_COORDINATES')
            ->doesntExpectOutputToContain($this->fakeToken)
            ->doesntExpectOutputToContain($sensitivePayloadMarker)
            ->doesntExpectOutputToContain("\e[31m")
            ->assertSuccessful();

        $this->assertSame(0, VendingMachine::query()->count());
        $this->assertSame(0, SybiVendingSyncRun::query()->count());
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_show_rejections_does_not_print_second_table_when_all_records_are_accepted(): void
    {
        Http::fake(['*' => Http::response([
            'ok' => true,
            'total' => 1,
            'data' => [$this->record()],
        ])]);

        $this->artisan('sybi:sync-vending --dry-run --show-rejections')
            ->doesntExpectOutputToContain('Rejected records')
            ->assertSuccessful();
    }

    public function test_normal_command_keeps_previous_output_without_rejection_details(): void
    {
        Http::fake(['*' => Http::response([
            'ok' => true,
            'total' => 1,
            'data' => [$this->record(['identificador_vending' => null])],
        ])]);

        $this->artisan('sybi:sync-vending --dry-run')
            ->expectsOutputToContain('DRY RUN')
            ->doesntExpectOutputToContain('Rejected records')
            ->doesntExpectOutputToContain('MISSING_VENDING_IDENTIFIER')
            ->assertSuccessful();

        $this->assertSame(0, VendingMachine::query()->count());
        $this->assertSame(0, SybiVendingSyncRun::query()->count());
    }

    public function test_diagnostic_identifiers_are_bounded_and_do_not_expose_non_scalar_values(): void
    {
        Http::fake(['*' => Http::response([
            'ok' => true,
            'total' => 2,
            'data' => [
                $this->record(['id_sucursal' => ['unexpected' => 'value']]),
                $this->record([
                    'id_sucursal' => 705,
                    'identificador_vending' => str_repeat('X', 101),
                ]),
            ],
        ])]);

        $this->artisan('sybi:sync-vending --dry-run --show-rejections')
            ->expectsOutputToContain('INVALID_SYBI_ID')
            ->expectsOutputToContain('INVALID_VENDING_IDENTIFIER')
            ->doesntExpectOutputToContain('unexpected')
            ->doesntExpectOutputToContain(str_repeat('X', 101))
            ->assertSuccessful();
    }

    private function record(array $overrides = []): array
    {
        return array_replace_recursive([
            'id_sucursal' => 701,
            'identificador_vending' => 'VM-701',
            'nombre_sucursal' => null,
            'ubicacion' => [
                'calle' => null,
                'colonia' => null,
                'codigo_postal' => null,
                'id_ciudad' => null,
                'id_estado' => null,
                'direccion_completa' => null,
            ],
            'latitud' => 19.4326,
            'longitud' => -99.1332,
        ], $overrides);
    }
}
