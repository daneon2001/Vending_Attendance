<?php

namespace Tests\Feature\Integrations;

use App\Enums\Vending\SybiVendingErrorCode;
use App\Integrations\Sybi\SybiVendingApiClient;
use App\Integrations\Sybi\SybiVendingApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SybiVendingApiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sybi.vending.url', 'https://sybi.invalid/v1/public/sucursales-vending');
        config()->set('sybi.vending.token', 'test-only-random-value');
    }

    public function test_client_sends_bearer_credential_server_side_and_parses_valid_contract(): void
    {
        Http::fake([
            '*' => Http::response(['ok' => true, 'total' => 1, 'data' => [$this->record()]], 200),
        ]);

        $response = app(SybiVendingApiClient::class)->fetchMachines();

        $this->assertSame(1, $response->total);
        $this->assertCount(1, $response->data);
        Http::assertSent(fn ($request): bool => $request->url() === config('sybi.vending.url')
            && $request->hasHeader('Authorization', 'Bearer '.config('sybi.vending.token'))
            && $request->hasHeader('Accept', 'application/json')
        );
    }

    public function test_empty_catalog_is_a_valid_transport_response(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'total' => 0, 'data' => []])]);

        $response = app(SybiVendingApiClient::class)->fetchMachines();

        $this->assertSame(0, $response->total);
        $this->assertSame([], $response->data);
    }

    public function test_declared_total_mismatch_is_reported_as_a_non_blocking_warning(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'total' => 2, 'data' => [$this->record()]])]);

        $response = app(SybiVendingApiClient::class)->fetchMachines();

        $this->assertSame(['DECLARED_TOTAL_MISMATCH'], $response->warnings);
        $this->assertCount(1, $response->data);
    }

    #[DataProvider('safeHttpErrors')]
    public function test_http_errors_are_mapped_without_exposing_response_body(
        int $status,
        SybiVendingErrorCode $expected,
    ): void {
        Http::fake(['*' => Http::response(['secret' => 'must-not-escape'], $status)]);

        try {
            app(SybiVendingApiClient::class)->fetchMachines();
            $this->fail('Expected a sanitized integration exception.');
        } catch (SybiVendingApiException $exception) {
            $this->assertSame($expected, $exception->errorCode);
            $this->assertSame($status, $exception->httpStatus);
            $this->assertStringNotContainsString('must-not-escape', $exception->getMessage());
        }
    }

    public static function safeHttpErrors(): array
    {
        return [
            'unauthorized' => [401, SybiVendingErrorCode::AUTH_ERROR],
            'validation' => [422, SybiVendingErrorCode::VALIDATION_ERROR],
            'server' => [500, SybiVendingErrorCode::SOURCE_ERROR],
            'unavailable' => [503, SybiVendingErrorCode::SOURCE_NOT_CONFIGURED],
        ];
    }

    public function test_connection_failure_is_classified(): void
    {
        Http::fake(fn () => throw new ConnectionException('test timeout'));
        $this->assertErrorCode(SybiVendingErrorCode::NETWORK_ERROR);
    }

    public function test_invalid_json_is_classified(): void
    {
        Http::fake(['*' => Http::response('{not json', 200)]);
        $this->assertErrorCode(SybiVendingErrorCode::INVALID_RESPONSE);
    }

    public function test_invalid_schema_is_classified(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'total' => '1', 'data' => []], 200)]);
        $this->assertErrorCode(SybiVendingErrorCode::SCHEMA_ERROR);
    }

    public function test_missing_server_configuration_fails_before_an_http_request(): void
    {
        config()->set('sybi.vending.token', '');
        Http::fake();

        $this->assertErrorCode(SybiVendingErrorCode::SOURCE_NOT_CONFIGURED);
        Http::assertNothingSent();
    }

    private function assertErrorCode(SybiVendingErrorCode $expected): void
    {
        try {
            app(SybiVendingApiClient::class)->fetchMachines();
            $this->fail('Expected a SYBIML integration exception.');
        } catch (SybiVendingApiException $exception) {
            $this->assertSame($expected, $exception->errorCode);
        }
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
