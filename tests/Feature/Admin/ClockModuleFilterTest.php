<?php

namespace Tests\Feature\Admin;

use App\Models\Clock;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
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

    public function test_clocks_summary_uses_filtered_universe_but_ignores_monitoring_filter_for_kpis(): void
    {
        $this->actingAs(User::factory()->create());
        $company = Company::query()->create([
            'name' => 'Medical Life',
            'code' => 'ML',
            'status' => 1,
        ]);

        foreach (range(1, 79) as $index) {
            Clock::query()->create([
                'clock_name' => 'Reloj Online '.$index,
                'serial_number' => 'ON-'.$index,
                'company_id' => $company->id,
                'status' => 1,
                'last_heartbeat_at' => Clock::heartbeatOnlineThreshold()->copy()->addMinute(),
                'monitoring_status' => 'online',
                'program_status' => 'online',
            ]);
        }

        foreach (range(1, 3) as $index) {
            Clock::query()->create([
                'clock_name' => 'Reloj Warning '.$index,
                'serial_number' => 'WR-'.$index,
                'company_id' => $company->id,
                'status' => 1,
                'last_heartbeat_at' => Clock::heartbeatOnlineThreshold()->copy()->subMinute(),
                'monitoring_status' => 'warning',
                'program_status' => 'online',
            ]);
        }

        foreach (range(1, 2) as $index) {
            Clock::query()->create([
                'clock_name' => 'Reloj Offline '.$index,
                'serial_number' => 'OFF-'.$index,
                'company_id' => $company->id,
                'status' => 1,
                'last_heartbeat_at' => null,
                'monitoring_status' => 'offline',
                'program_status' => 'offline',
            ]);
        }

        $response = $this->getJson(route('clocks.list', [
            'company_id' => $company->id,
            'monitoring_status' => 'online',
            'per_page' => 12,
        ]));

        $response->assertOk()
            ->assertJsonCount(12, 'data')
            ->assertJsonPath('meta.total', 79)
            ->assertJsonPath('summary.online', 79)
            ->assertJsonPath('summary.warnings', 3)
            ->assertJsonPath('summary.offline', 2)
            ->assertJsonPath('summary.total', 84);
    }

    public function test_online_filter_only_returns_clocks_with_recent_heartbeat(): void
    {
        $this->actingAs(User::factory()->create());
        $company = Company::query()->create([
            'name' => 'Medical Life',
            'code' => 'ML',
            'status' => 1,
        ]);

        Clock::query()->create([
            'clock_name' => 'Reloj Reciente',
            'serial_number' => 'HB-RECENT',
            'company_id' => $company->id,
            'status' => 1,
            'last_heartbeat_at' => Clock::heartbeatOnlineThreshold()->copy()->addMinute(),
            'monitoring_status' => 'online',
            'program_status' => 'online',
        ]);

        Clock::query()->create([
            'clock_name' => 'Reloj Rezagado',
            'serial_number' => 'HB-STALE',
            'company_id' => $company->id,
            'status' => 1,
            'last_heartbeat_at' => Clock::heartbeatOnlineThreshold()->copy()->subMinute(),
            'monitoring_status' => 'online',
            'program_status' => 'online',
        ]);

        Clock::query()->create([
            'clock_name' => 'Reloj Sin Heartbeat',
            'serial_number' => 'HB-NONE',
            'company_id' => $company->id,
            'status' => 1,
            'last_heartbeat_at' => null,
            'monitoring_status' => 'offline',
            'program_status' => 'offline',
        ]);

        Clock::query()->create([
            'clock_name' => 'Reloj Inactivo Reciente',
            'serial_number' => 'HB-INACTIVE',
            'company_id' => $company->id,
            'status' => 0,
            'last_heartbeat_at' => Clock::heartbeatOnlineThreshold()->copy()->addMinute(),
            'monitoring_status' => 'online',
            'program_status' => 'online',
        ]);

        $response = $this->getJson(route('clocks.list', [
            'company_id' => $company->id,
            'monitoring_status' => 'online',
            'per_page' => 12,
        ]));

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.serial_number', 'HB-RECENT')
            ->assertJsonPath('data.0.monitoring_status', 'online')
            ->assertJsonPath('data.0.is_online', true)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('summary.online', 1)
            ->assertJsonPath('summary.warnings', 1)
            ->assertJsonPath('summary.offline', 1)
            ->assertJsonPath('summary.total', 3);
    }

    public function test_monitoring_summary_and_default_listing_only_use_active_clocks(): void
    {
        $this->actingAs(User::factory()->create());
        $company = Company::query()->create([
            'name' => 'Medical Life',
            'code' => 'ML',
            'status' => 1,
        ]);

        foreach (range(1, 3) as $index) {
            Clock::query()->create([
                'clock_name' => 'Activo Online '.$index,
                'serial_number' => 'AO-'.$index,
                'company_id' => $company->id,
                'status' => 1,
                'last_heartbeat_at' => Clock::heartbeatOnlineThreshold()->copy()->addMinute(),
                'monitoring_status' => 'online',
                'program_status' => 'online',
            ]);
        }

        foreach (range(1, 2) as $index) {
            Clock::query()->create([
                'clock_name' => 'Activo Warning '.$index,
                'serial_number' => 'AW-'.$index,
                'company_id' => $company->id,
                'status' => 1,
                'last_heartbeat_at' => Clock::heartbeatOnlineThreshold()->copy()->subMinute(),
                'monitoring_status' => 'warning',
                'program_status' => 'online',
            ]);
        }

        foreach (range(1, 2) as $index) {
            Clock::query()->create([
                'clock_name' => 'Activo Offline Null '.$index,
                'serial_number' => 'AN-'.$index,
                'company_id' => $company->id,
                'status' => 1,
                'last_heartbeat_at' => null,
                'monitoring_status' => 'offline',
                'program_status' => 'offline',
            ]);
        }

        foreach (range(1, 2) as $index) {
            Clock::query()->create([
                'clock_name' => 'Activo Offline Ayer '.$index,
                'serial_number' => 'AY-'.$index,
                'company_id' => $company->id,
                'status' => 1,
                'last_heartbeat_at' => Clock::operationsDayStartThreshold()->copy()->subMinute(),
                'monitoring_status' => 'offline',
                'program_status' => 'offline',
            ]);
        }

        foreach (range(1, 5) as $index) {
            Clock::query()->create([
                'clock_name' => 'Inactivo Reciente '.$index,
                'serial_number' => 'IR-'.$index,
                'company_id' => $company->id,
                'status' => 0,
                'last_heartbeat_at' => Clock::heartbeatOnlineThreshold()->copy()->addMinute(),
                'monitoring_status' => 'online',
                'program_status' => 'online',
            ]);
        }

        $defaultResponse = $this->getJson(route('clocks.list', [
            'company_id' => $company->id,
            'per_page' => 20,
        ]));

        $defaultResponse->assertOk()
            ->assertJsonCount(9, 'data')
            ->assertJsonPath('meta.total', 9)
            ->assertJsonPath('summary.online', 3)
            ->assertJsonPath('summary.warnings', 2)
            ->assertJsonPath('summary.offline', 4)
            ->assertJsonPath('summary.total', 9);

        $this->assertSame(
            [1],
            collect($defaultResponse->json('data'))->pluck('status')->unique()->values()->all()
        );

        $inactiveResponse = $this->getJson(route('clocks.list', [
            'company_id' => $company->id,
            'status' => 0,
            'per_page' => 20,
        ]));

        $inactiveResponse->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('summary.online', 3)
            ->assertJsonPath('summary.warnings', 2)
            ->assertJsonPath('summary.offline', 4)
            ->assertJsonPath('summary.total', 9);
    }

    public function test_monitoring_filters_split_active_clocks_between_online_warning_and_offline(): void
    {
        $this->actingAs(User::factory()->create());
        $company = Company::query()->create([
            'name' => 'Medical Life',
            'code' => 'ML',
            'status' => 1,
        ]);

        Clock::query()->create([
            'clock_name' => 'Online Activo',
            'serial_number' => 'FLT-ONLINE',
            'company_id' => $company->id,
            'status' => 1,
            'last_heartbeat_at' => Clock::heartbeatOnlineThreshold()->copy()->addMinute(),
            'monitoring_status' => 'online',
            'program_status' => 'online',
        ]);

        Clock::query()->create([
            'clock_name' => 'Warning Activo',
            'serial_number' => 'FLT-WARNING',
            'company_id' => $company->id,
            'status' => 1,
            'last_heartbeat_at' => Clock::heartbeatOnlineThreshold()->copy()->subMinute(),
            'monitoring_status' => 'warning',
            'program_status' => 'online',
        ]);

        Clock::query()->create([
            'clock_name' => 'Offline Activo',
            'serial_number' => 'FLT-OFFLINE',
            'company_id' => $company->id,
            'status' => 1,
            'last_heartbeat_at' => Clock::operationsDayStartThreshold()->copy()->subMinute(),
            'monitoring_status' => 'offline',
            'program_status' => 'offline',
        ]);

        Clock::query()->create([
            'clock_name' => 'Online Inactivo',
            'serial_number' => 'FLT-INACTIVE',
            'company_id' => $company->id,
            'status' => 0,
            'last_heartbeat_at' => Clock::heartbeatOnlineThreshold()->copy()->addMinute(),
            'monitoring_status' => 'online',
            'program_status' => 'online',
        ]);

        $this->getJson(route('clocks.list', [
            'company_id' => $company->id,
            'monitoring_status' => 'warning',
        ]))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.serial_number', 'FLT-WARNING');

        $this->getJson(route('clocks.list', [
            'company_id' => $company->id,
            'monitoring_status' => 'offline',
        ]))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.serial_number', 'FLT-OFFLINE');

        $this->getJson(route('clocks.list', [
            'company_id' => $company->id,
            'status' => 0,
            'monitoring_status' => 'online',
        ]))
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_clocks_page_exposes_summary_for_all_matching_clocks(): void
    {
        $this->actingAs(User::factory()->create());
        $company = Company::query()->create([
            'name' => 'Medical Life',
            'code' => 'ML',
            'status' => 1,
        ]);

        foreach (range(1, 79) as $index) {
            Clock::query()->create([
                'clock_name' => 'Reloj Online '.$index,
                'serial_number' => 'ONPAGE-'.$index,
                'company_id' => $company->id,
                'status' => 1,
                'last_heartbeat_at' => Clock::heartbeatOnlineThreshold()->copy()->addMinute(),
                'monitoring_status' => 'online',
                'program_status' => 'online',
            ]);
        }

        $this->get(route('clocks.index', [
            'company_id' => $company->id,
            'monitoring_status' => 'online',
            'per_page' => 12,
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Clocks/Index')
                ->where('initialClocks.meta.total', 79)
                ->where('summary.online', 79)
                ->where('summary.warnings', 0)
                ->where('summary.offline', 0)
                ->where('summary.total', 79)
            );
    }
}
