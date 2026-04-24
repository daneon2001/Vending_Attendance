<?php

namespace Tests\Feature\Admin;

use App\Models\Clock;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClockModuleFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_clocks_list_can_search_by_serial_number(): void
    {
        $this->actingAs(User::factory()->create());

        Clock::query()->create([
            'clock_name' => 'Reloj Norte',
            'serial_number' => 'SER-001',
            'status' => 1,
        ]);

        Clock::query()->create([
            'clock_name' => 'Reloj Sur',
            'serial_number' => 'SER-XYZ',
            'status' => 1,
        ]);

        $response = $this->getJson(route('clocks.list', [
            'q' => 'SER-XYZ',
        ]));

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.serial_number', 'SER-XYZ');
    }

    public function test_clocks_list_can_search_by_clock_name(): void
    {
        $this->actingAs(User::factory()->create());

        Clock::query()->create([
            'clock_name' => 'Reloj Acceso Principal',
            'serial_number' => 'A-1',
            'status' => 1,
        ]);

        Clock::query()->create([
            'clock_name' => 'Reloj Comedor',
            'serial_number' => 'B-1',
            'status' => 1,
        ]);

        $response = $this->getJson(route('clocks.list', [
            'q' => 'Comedor',
        ]));

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.clock_name', 'Reloj Comedor');
    }

    public function test_clocks_list_can_filter_by_company(): void
    {
        $this->actingAs(User::factory()->create());

        $companyA = Company::query()->create([
            'name' => 'Medical Life',
            'code' => 'ML',
            'status' => 1,
        ]);

        $companyB = Company::query()->create([
            'name' => 'Empresa Sur',
            'code' => 'SUR',
            'status' => 1,
        ]);

        Clock::query()->create([
            'clock_name' => 'Reloj A',
            'serial_number' => 'SER-A',
            'company_id' => $companyA->id,
            'status' => 1,
        ]);

        Clock::query()->create([
            'clock_name' => 'Reloj B',
            'serial_number' => 'SER-B',
            'company_id' => $companyB->id,
            'status' => 1,
        ]);

        $response = $this->getJson(route('clocks.list', [
            'company_id' => $companyA->id,
        ]));

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.company.id', $companyA->id)
            ->assertJsonPath('data.0.company.name', 'Medical Life');
    }

    public function test_clocks_list_can_filter_by_location(): void
    {
        $this->actingAs(User::factory()->create());

        $company = Company::query()->create([
            'name' => 'Medical Life',
            'code' => 'ML',
            'status' => 1,
        ]);

        $locationA = Location::query()->create([
            'company_id' => $company->id,
            'name' => 'Matriz',
            'code' => 'MTR',
            'status' => 1,
        ]);

        $locationB = Location::query()->create([
            'company_id' => $company->id,
            'name' => 'Sucursal',
            'code' => 'SCR',
            'status' => 1,
        ]);

        Clock::query()->create([
            'clock_name' => 'Reloj Matriz',
            'serial_number' => 'MTR-1',
            'company_id' => $company->id,
            'location_id' => $locationA->id,
            'status' => 1,
        ]);

        Clock::query()->create([
            'clock_name' => 'Reloj Sucursal',
            'serial_number' => 'SCR-1',
            'company_id' => $company->id,
            'location_id' => $locationB->id,
            'status' => 1,
        ]);

        $response = $this->getJson(route('clocks.list', [
            'location_id' => $locationA->id,
        ]));

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.location.id', $locationA->id)
            ->assertJsonPath('data.0.location.name', 'Matriz');
    }

    public function test_clocks_list_can_filter_unassigned_location(): void
    {
        $this->actingAs(User::factory()->create());

        $company = Company::query()->create([
            'name' => 'Medical Life',
            'code' => 'ML',
            'status' => 1,
        ]);

        $location = Location::query()->create([
            'company_id' => $company->id,
            'name' => 'Matriz',
            'code' => 'MTR',
            'status' => 1,
        ]);

        Clock::query()->create([
            'clock_name' => 'Reloj Sin Unidad',
            'serial_number' => 'NO-UNIT',
            'company_id' => $company->id,
            'location_id' => null,
            'status' => 1,
        ]);

        Clock::query()->create([
            'clock_name' => 'Reloj Con Unidad',
            'serial_number' => 'WITH-UNIT',
            'company_id' => $company->id,
            'location_id' => $location->id,
            'status' => 1,
        ]);

        $response = $this->getJson(route('clocks.list', [
            'location_id' => 'unassigned',
        ]));

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.serial_number', 'NO-UNIT')
            ->assertJsonPath('data.0.location.id', null);
    }

    public function test_clocks_list_keeps_filters_in_pagination_query_string(): void
    {
        $this->actingAs(User::factory()->create());

        $company = Company::query()->create([
            'name' => 'Medical Life',
            'code' => 'ML',
            'status' => 1,
        ]);

        Clock::query()->create([
            'clock_name' => 'Reloj Alpha',
            'serial_number' => 'ALPHA-1',
            'company_id' => $company->id,
            'status' => 1,
        ]);

        Clock::query()->create([
            'clock_name' => 'Reloj Alpha 2',
            'serial_number' => 'ALPHA-2',
            'company_id' => $company->id,
            'status' => 1,
        ]);

        $response = $this->getJson(route('clocks.list', [
            'q' => 'Alpha',
            'company_id' => $company->id,
            'per_page' => 1,
        ]));

        $response->assertOk()
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2);

        $nextPageUrl = (string) $response->json('meta.next_page_url');

        $this->assertStringContainsString('q=Alpha', $nextPageUrl);
        $this->assertStringContainsString('company_id='.$company->id, $nextPageUrl);
        $this->assertStringContainsString('per_page=1', $nextPageUrl);
    }
}
