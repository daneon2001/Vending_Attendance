<?php

namespace Tests\Feature\Support;

use App\Models\Permission;
use App\Models\Role;
use App\Models\SupportIntegration;
use App\Models\SupportTicketEvent;
use App\Models\User;
use App\Services\Support\SupportActor;
use App\Services\Support\SupportIntegrationTokens;
use App\Services\Support\SupportTicketService;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Api\V1\VendingDeviceApiTestCase;

class SupportDomainTest extends VendingDeviceApiTestCase
{
    private function userWith(array $actions): User
    {
        $user = User::factory()->create(['estatus' => true]);
        $role = Role::query()->create(['name' => 'Support test '.Str::uuid()]);
        foreach ($actions as $action) {
            $permission = Permission::query()->firstOrCreate(['module' => 'support', 'action' => $action], ['name' => 'Support '.$action]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->roles()->attach($role);

        return $user;
    }

    private function data(array $overrides = []): array
    {
        return array_replace([
            'client_operation_uuid' => (string) Str::uuid(), 'category' => 'OTHER',
            'title' => 'Prueba de soporte', 'description' => 'Una incidencia de prueba no sensible.',
        ], $overrides);
    }

    public function test_device_ticket_is_idempotent_and_machine_authority_is_server_derived(): void
    {
        $fixture = $this->provisionedDevice($this->machine());
        $data = $this->data();
        $path = '/api/v1/device/support/tickets';
        $first = $this->signedDeviceRequest('POST', $path, $data, $fixture['device'], $fixture['credential'])->assertCreated();
        $second = $this->signedDeviceRequest('POST', $path, $data, $fixture['device'], $fixture['credential'])->assertCreated();
        $this->assertSame($first->json('ticket.uuid'), $second->json('ticket.uuid'));
        $this->assertMatchesRegularExpression('/^INC-\\d{4}-\\d{6,}$/', $first->json('ticket.folio'));
        $this->assertDatabaseCount('support_tickets', 1);
        $this->assertDatabaseCount('support_ticket_events', 1);
        $this->assertDatabaseHas('support_tickets', ['device_id' => $fixture['device']->id, 'source' => 'MANUAL_MOBILE', 'priority' => 'NORMAL']);
        $this->signedDeviceRequest('POST', $path, $this->data(['vending_machine_id' => $this->machine()->id]), $fixture['device'], $fixture['credential'])->assertUnprocessable();
        $this->signedDeviceRequest('POST', $path, [...$data, 'description' => 'Contenido diferente'], $fixture['device'], $fixture['credential'])->assertConflict();
        $this->assertDatabaseCount('support_tickets', 1);
    }

    public function test_device_cannot_read_or_comment_on_another_device_ticket(): void
    {
        $a = $this->provisionedDevice($this->machine());
        $b = $this->provisionedDevice($this->machine());
        $ticket = app(SupportTicketService::class)->create(SupportActor::device($a['device']), $this->data())['ticket'];
        $path = '/api/v1/device/support/tickets/'.$ticket['uuid'];
        $this->signedDeviceRequest('GET', $path, [], $b['device'], $b['credential'])->assertNotFound();
        $this->signedDeviceRequest('POST', $path.'/comments', ['client_operation_uuid' => (string) Str::uuid(), 'body' => 'No autorizado'], $b['device'], $b['credential'])->assertNotFound();
        $this->assertDatabaseCount('support_ticket_events', 1);
    }

    public function test_web_workflow_assignment_comments_and_closed_immutability(): void
    {
        $user = $this->userWith(['manage']);
        $actor = SupportActor::user($user);
        $service = app(SupportTicketService::class);
        $ticket = $service->create($actor, $this->data(['vending_machine_id' => $this->machine()->id]))['ticket'];
        $this->assertSame('MANUAL_WEB', $ticket['source']);
        $assigned = $service->assign($actor, $ticket['uuid'], ['client_operation_uuid' => (string) Str::uuid(), 'assignee_id' => $user->id]);
        $this->assertSame($user->id, $assigned['ticket']['assignee']['id']);
        $comment = ['client_operation_uuid' => (string) Str::uuid(), 'body' => '<script>not rendered as HTML</script>'];
        $service->comment($actor, $ticket['uuid'], $comment);
        $service->comment($actor, $ticket['uuid'], $comment);
        $this->assertSame(1, SupportTicketEvent::where('kind', 'support.comment.created')->count());
        foreach (['IN_PROGRESS', 'WAITING', 'RESOLVED', 'CLOSED'] as $status) {
            $result = $service->transition($actor, $ticket['uuid'], ['client_operation_uuid' => (string) Str::uuid(), 'status' => $status, 'resolution' => 'Revisión completada.']);
            $this->assertSame($status, $result['ticket']['status']);
        }
        $before = SupportTicketEvent::count();
        try {
            $service->comment($actor, $ticket['uuid'], ['client_operation_uuid' => (string) Str::uuid(), 'body' => 'No permitido']);
            $this->fail('Closed ticket accepted comment.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame($before, SupportTicketEvent::count());
        $this->expectException(\LogicException::class);
        SupportTicketEvent::first()->update(['body' => 'rewrite']);
    }

    public function test_invalid_transition_and_unprivileged_user_leave_database_unchanged(): void
    {
        $actor = SupportActor::user($this->userWith(['manage']));
        $service = app(SupportTicketService::class);
        $ticket = $service->create($actor, $this->data(['vending_machine_id' => $this->machine()->id]))['ticket'];
        try {
            $service->transition($actor, $ticket['uuid'], ['client_operation_uuid' => (string) Str::uuid(), 'status' => 'CLOSED']);
            $this->fail('OPEN to CLOSED must fail.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $viewer = SupportActor::user($this->userWith(['view', 'view_all']));
        try {
            $service->assign($viewer, $ticket['uuid'], ['client_operation_uuid' => (string) Str::uuid(), 'assignee_id' => null]);
            $this->fail('Viewer mutation must fail.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('support_ticket_events', 1);
        $this->assertDatabaseHas('support_tickets', ['status' => 'OPEN', 'assignee_id' => null]);
    }

    public function test_location_is_optional_and_existing_geofence_service_is_used(): void
    {
        $machine = $this->machine();
        $this->geofence($machine);
        $device = $this->provisionedDevice($machine)['device'];
        $service = app(SupportTicketService::class);
        $without = $service->create(SupportActor::device($device), $this->data())['ticket'];
        $this->assertFalse($without['location_available']);
        $inside = $service->create(SupportActor::device($device), $this->data([
            'reported_at' => now('UTC')->toISOString(),
            'location' => ['latitude' => 19.4326, 'longitude' => -99.1332, 'accuracy_m' => 5, 'captured_at' => now('UTC')->toISOString()],
        ]))['ticket'];
        $this->assertSame('INSIDE', $inside['geofence_result']);
        $this->assertArrayNotHasKey('location', $inside);
        $this->assertArrayNotHasKey('credential_secret', $inside);
    }

    private function integration(array $scopes, array $machines): array
    {
        $integration = SupportIntegration::create(['uuid' => (string) Str::uuid(), 'system_key' => 'TEST_'.Str::upper(Str::random(10)), 'name' => 'Integration test', 'active' => true]);
        $integration->machines()->attach($machines);
        $token = app(SupportIntegrationTokens::class)->issue($integration, 'Test only', $scopes, now()->addHour());

        return [$integration, $token->plainTextToken];
    }

    public function test_service_authentication_scopes_and_legacy_isolation(): void
    {
        $machine = $this->machine();
        [$integration, $token] = $this->integration(['support.tickets.read'], [$machine->id]);
        $base = '/api/v1/support/integration';
        $this->getJson($base.'/tickets')->assertUnauthorized();
        $this->withToken($token)->getJson($base.'/tickets')->assertOk();
        $this->withToken($token)->postJson($base.'/tickets', $this->data(['vending_machine_id' => $machine->id]))->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/vending-machines/'.$machine->uuid)->assertUnauthorized();
        $human = $this->userWith(['manage'])->createToken('Human', ['*'])->plainTextToken;
        $this->withToken($human)->getJson($base.'/tickets')->assertUnauthorized();
        $this->assertDatabaseCount('support_tickets', 0);
        $integration->update(['active' => false]);
        $this->withToken($token)->getJson($base.'/tickets')->assertUnauthorized();
    }

    public function test_external_reference_uniqueness_scoped_idempotency_and_incremental_feed(): void
    {
        $machine = $this->machine();
        [, $token] = $this->integration(['support.tickets.read', 'support.tickets.create', 'support.comments.create'], [$machine->id]);
        $base = '/api/v1/support/integration';
        $data = $this->data(['vending_machine_id' => $machine->id, 'external_reference' => 'case-001']);
        $ticket = $this->withToken($token)->postJson($base.'/tickets', $data)->assertCreated()->json('ticket');
        $this->withToken($token)->postJson($base.'/tickets', [...$data, 'client_operation_uuid' => (string) Str::uuid()])->assertCreated()->assertJsonPath('ticket.uuid', $ticket['uuid']);
        $this->assertDatabaseCount('support_tickets', 1);
        $this->withToken($token)->postJson($base.'/tickets', [...$data, 'client_operation_uuid' => (string) Str::uuid(), 'description' => 'Conflict'])->assertConflict();
        $comment = ['client_operation_uuid' => (string) Str::uuid(), 'body' => 'Comentario de integración'];
        $this->withToken($token)->postJson($base.'/tickets/'.$ticket['uuid'].'/comments', $comment)->assertOk();
        $page1 = $this->withToken($token)->getJson($base.'/changes?limit=1')->assertOk()->assertJsonPath('has_more', true);
        $cursor = $page1->json('next_sequence');
        $this->withToken($token)->getJson($base.'/changes?after_sequence='.$cursor)->assertOk()->assertJsonCount(1, 'events')->assertJsonPath('has_more', false);
        $other = $this->machine();
        $this->withToken($token)->postJson($base.'/tickets', $this->data(['vending_machine_id' => $other->id]))->assertNotFound();
        [, $otherToken] = $this->integration(['support.tickets.read'], [$other->id]);
        $this->withToken($otherToken)->getJson($base.'/tickets/'.$ticket['uuid'])->assertNotFound();
        $this->withToken($otherToken)->getJson($base.'/changes')->assertOk()->assertJsonCount(0, 'events');
    }
}
