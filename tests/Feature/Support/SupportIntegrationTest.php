<?php

namespace Tests\Feature\Support;

use App\Models\Permission;
use App\Models\Role;
use App\Models\SupportIntegration;
use App\Models\SupportTicketEvent;
use App\Models\User;
use App\Services\Support\SupportIntegrationTokens;
use Illuminate\Support\Str;
use Tests\Feature\Api\V1\VendingDeviceApiTestCase;

class SupportIntegrationTest extends VendingDeviceApiTestCase
{
    private function principal(array $scopes): array
    {
        $machine = $this->machine();
        $service = SupportIntegration::create(['uuid' => (string) Str::uuid(), 'system_key' => 'SERVICE_'.Str::random(10), 'name' => 'Test service', 'active' => true]);
        $service->machines()->attach($machine);
        $token = app(SupportIntegrationTokens::class)->issue($service, 'Test', $scopes, now()->addHour());

        return [$service, $machine, $token];
    }

    private function report($machine, array $extra = []): array
    {
        return [...['client_operation_uuid' => (string) Str::uuid(), 'vending_machine_id' => $machine->id,
            'category' => 'OTHER', 'title' => 'Integración de prueba', 'description' => 'Prueba controlada'], ...$extra];
    }

    public function test_scoped_external_assignment_workflow_resolution_and_closed_immutability(): void
    {
        [$service, $machine, $token] = $this->principal(config('support.integration_scopes'));
        $this->withToken($token->plainTextToken);
        $base = '/api/v1/support/integration/tickets';
        $uuid = $this->postJson($base, $this->report($machine))->assertCreated()->json('ticket.uuid');
        $user = User::factory()->create(['estatus' => true]);
        $role = Role::create(['name' => 'Support resolver']);
        $permission = Permission::firstOrCreate(['module' => 'support', 'action' => 'resolve'], ['name' => 'Resolve']);
        $role->permissions()->attach($permission);
        $user->roles()->attach($role);
        $this->postJson($base.'/'.$uuid.'/assign', ['client_operation_uuid' => (string) Str::uuid(), 'assignee_id' => $user->id])
            ->assertOk()->assertJsonPath('ticket.assignee.id', $user->id);
        $this->postJson($base.'/'.$uuid.'/transition', ['client_operation_uuid' => (string) Str::uuid(), 'status' => 'CLOSED'])->assertConflict();
        $this->postJson($base.'/'.$uuid.'/transition', ['client_operation_uuid' => (string) Str::uuid(), 'status' => 'IN_PROGRESS'])->assertOk();
        $this->postJson($base.'/'.$uuid.'/transition', ['client_operation_uuid' => (string) Str::uuid(), 'status' => 'RESOLVED'])->assertUnprocessable();
        $this->postJson($base.'/'.$uuid.'/transition', ['client_operation_uuid' => (string) Str::uuid(), 'status' => 'RESOLVED', 'resolution' => 'Revisión finalizada'])->assertOk();
        $this->postJson($base.'/'.$uuid.'/transition', ['client_operation_uuid' => (string) Str::uuid(), 'status' => 'CLOSED'])->assertOk();
        $count = SupportTicketEvent::count();
        $this->postJson($base.'/'.$uuid.'/comments', ['client_operation_uuid' => (string) Str::uuid(), 'body' => 'No editar cerrado'])->assertConflict();
        $this->assertSame($count, SupportTicketEvent::count());
    }

    public function test_rotation_preserves_operation_identity_while_expiration_revocation_and_cookies_fail_closed(): void
    {
        [$service, $machine, $old] = $this->principal(['support.tickets.read', 'support.tickets.create']);
        $base = '/api/v1/support/integration/tickets';
        $data = $this->report($machine);
        $uuid = $this->withToken($old->plainTextToken)->postJson($base, $data)->assertCreated()->json('ticket.uuid');
        $replacement = app(SupportIntegrationTokens::class)->issue($service, 'Rotated', ['support.tickets.read', 'support.tickets.create'], now()->addHour());
        app(SupportIntegrationTokens::class)->revoke($service, $old->accessToken->id);
        $this->withToken($old->plainTextToken)->getJson($base)->assertUnauthorized();
        $this->withToken($replacement->plainTextToken)->postJson($base, $data)->assertCreated()->assertJsonPath('ticket.uuid', $uuid);
        $this->assertDatabaseCount('support_tickets', 1);
        $this->withHeader('Cookie', 'session=not-an-authority')->getJson($base)->assertUnauthorized();
        $this->flushHeaders();
        $replacement->accessToken->update(['expires_at' => now()->subSecond()]);
        $this->withToken($replacement->plainTextToken)->getJson($base)->assertUnauthorized();
    }

    public function test_transition_scope_does_not_imply_resolve_or_manage_and_no_wildcard_tokens_are_issued(): void
    {
        [$service, $machine, $token] = $this->principal(['support.tickets.read', 'support.tickets.create', 'support.tickets.transition']);
        $this->withToken($token->plainTextToken);
        $base = '/api/v1/support/integration/tickets';
        $uuid = $this->postJson($base, $this->report($machine))->assertCreated()->json('ticket.uuid');
        $this->postJson($base.'/'.$uuid.'/transition', ['client_operation_uuid' => (string) Str::uuid(), 'status' => 'RESOLVED', 'resolution' => 'No autorizado'])->assertForbidden();
        $this->postJson($base.'/'.$uuid.'/transition', ['client_operation_uuid' => (string) Str::uuid(), 'status' => 'CANCELLED'])->assertForbidden();
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(SupportIntegrationTokens::class)->issue($service, 'Forbidden wildcard', ['*'], now()->addHour());
    }

    public function test_external_reference_is_per_principal_and_changed_location_or_time_conflicts(): void
    {
        [$service, $machine, $token] = $this->principal(['support.tickets.create']);
        $base = '/api/v1/support/integration/tickets';
        $data = $this->report($machine, ['external_reference' => 'remote-12']);
        $this->withToken($token->plainTextToken)->postJson($base, $data)->assertCreated();
        $this->postJson($base, [...$data, 'client_operation_uuid' => (string) Str::uuid(), 'reported_at' => now('UTC')->toISOString()])->assertConflict();
        $this->postJson($base, [...$data, 'client_operation_uuid' => (string) Str::uuid(),
            'reported_at' => now('UTC')->toISOString(),
            'location' => ['latitude' => 19.4326, 'longitude' => -99.1332, 'accuracy_m' => 5, 'captured_at' => now('UTC')->toISOString()]])->assertConflict();
        [$other, $otherMachine, $otherToken] = $this->principal(['support.tickets.create']);
        $this->withToken($otherToken->plainTextToken)->postJson($base, $this->report($otherMachine, ['external_reference' => 'remote-12']))->assertCreated();
        $this->assertDatabaseCount('support_tickets', 2);
    }

    public function test_api_pagination_and_date_boundaries_are_explicit_cdmx_and_folio_is_searchable(): void
    {
        [$service, $machine, $token] = $this->principal(['support.tickets.create', 'support.tickets.read']);
        $this->withToken($token->plainTextToken);
        $base = '/api/v1/support/integration/tickets';
        $first = $this->postJson($base, $this->report($machine, ['reported_at' => '2026-09-01T05:59:59Z']))->assertCreated()->json('ticket');
        $this->postJson($base, $this->report($machine, ['reported_at' => '2026-09-01T06:00:00Z']))->assertCreated();
        $this->getJson($base.'?from=2026-09-01&to=2026-09-01')->assertOk()->assertJsonPath('pagination.total', 1);
        $this->getJson($base.'?search='.urlencode($first['folio']))->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('tickets.0.uuid', $first['uuid']);
        $this->getJson($base.'?limit=1&page=2')->assertOk()->assertJsonPath('pagination.total', 2)->assertJsonCount(1, 'tickets');
        $this->getJson($base.'?limit=1001')->assertUnprocessable();
    }
}
