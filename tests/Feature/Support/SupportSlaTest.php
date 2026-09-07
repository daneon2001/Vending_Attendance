<?php

namespace Tests\Feature\Support;

use App\Models\Permission;
use App\Models\Role;
use App\Models\SupportPolicyVersion;
use App\Models\SupportTicket;
use App\Models\SupportTicketEvent;
use App\Models\User;
use App\Services\Support\SupportActor;
use App\Services\Support\SupportPolicyService;
use App\Services\Support\SupportSlaService;
use App\Services\Support\SupportTicketService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Api\V1\VendingDeviceApiTestCase;

class SupportSlaTest extends VendingDeviceApiTestCase
{
    public function test_policy_requires_configure_and_does_not_write_on_denial(): void
    {
        try {
            app(SupportPolicyService::class)->publish($this->actor(['view']), $this->policyInput());
            $this->fail('Configure permission is required.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('support_policy_versions', 0);
    }

    public function test_policy_version_counter_and_replay_are_persistent_and_content_immutable(): void
    {
        $actor = $this->actor(['configure']);
        $input = $this->policyInput();
        $service = app(SupportPolicyService::class);
        $first = $service->publish($actor, $input);
        $this->assertSame($first, $service->publish($actor, $input));
        $this->assertSame(1, $first['version']);
        $second = $service->publish($actor, [...$input, 'client_operation_uuid' => (string) Str::uuid(), 'label' => 'DEMO next']);
        $this->assertSame(2, $second['version']);
        $this->assertDatabaseCount('support_policy_versions', 2);
        $this->assertDatabaseHas('audit_logs', ['event' => 'support.policy.published']);
        $this->expectException(\LogicException::class);
        SupportPolicyVersion::query()->firstOrFail()->update(['payload' => ['sla' => ['enabled' => false]]]);
    }

    public function test_invalid_sla_targets_and_unknown_payload_fields_fail_closed(): void
    {
        $actor = $this->actor(['configure']);
        foreach ([
            ['enabled' => true, 'response_minutes' => 0, 'resolution_minutes' => 60, 'warning_minutes' => 5],
            ['enabled' => true, 'response_minutes' => 10, 'resolution_minutes' => 60, 'warning_minutes' => 20],
            ['enabled' => true, 'response_minutes' => 10, 'resolution_minutes' => 60, 'warning_minutes' => 5, 'skip_audit' => true],
        ] as $sla) {
            try {
                app(SupportPolicyService::class)->publish($actor, [...$this->policyInput(), 'payload' => ['sla' => $sla]]);
                $this->fail('Invalid policy must be rejected.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('support_policy_versions', 0);
            }
        }
    }

    public function test_no_policy_means_no_deadlines_and_no_automatic_sla_activity(): void
    {
        $ticket = $this->ticket();
        $this->assertNull($ticket->response_due_at);
        $this->assertNull($ticket->resolution_due_at);
        $this->assertSame(0, app(SupportSlaService::class)->scan()['processed']);
        $this->assertFalse($ticket->response_breached);
    }

    public function test_sla_snapshots_receipt_time_utc_and_is_not_rewritten_by_later_policy(): void
    {
        $this->freezeTime();
        $actor = $this->actor(['configure']);
        app(SupportPolicyService::class)->publish($actor, $this->policyInput());
        $ticket = $this->ticket(['reported_at' => now()->subDays(2)->toIso8601String()]);
        $this->assertTrue($ticket->response_due_at->equalTo($ticket->created_at->copy()->addMinutes(10)));
        $this->assertTrue($ticket->resolution_due_at->equalTo($ticket->created_at->copy()->addMinutes(60)));
        $snapshot = $ticket->policy_snapshot;
        $input = $this->policyInput();
        $input['payload']['sla']['response_minutes'] = 20;
        app(SupportPolicyService::class)->publish($actor, $input);
        DB::transaction(fn () => app(SupportSlaService::class)->initialize($ticket));
        $this->assertSame($snapshot, $ticket->fresh()->policy_snapshot);
        $this->assertSame(1, $ticket->policy_version_id);
    }

    public function test_warning_and_breach_are_once_and_waiting_does_not_pause(): void
    {
        $this->freezeTime();
        app(SupportPolicyService::class)->publish($this->actor(['configure']), $this->policyInput());
        $ticket = $this->ticket();
        app(SupportTicketService::class)->transition(SupportActor::system(), $ticket->uuid, [
            'client_operation_uuid' => (string) Str::uuid(), 'status' => 'WAITING',
        ]);
        $this->travel(5)->minutes();
        $service = app(SupportSlaService::class);
        $service->scan();
        $service->scan();
        $this->assertSame(1, SupportTicketEvent::query()->where('kind', 'support.sla.warning')->count());
        $this->assertTrue($ticket->fresh()->response_warned);
        $this->travel(5)->minutes();
        $service->scan();
        $service->scan();
        $this->assertTrue($ticket->fresh()->response_breached);
        $this->assertSame(1, SupportTicketEvent::query()->where('kind', 'support.sla.breached')->count());
        $this->assertSame('WAITING', $ticket->fresh()->status);
    }

    public function test_first_response_and_resolution_before_due_suppress_breach(): void
    {
        $this->freezeTime();
        app(SupportPolicyService::class)->publish($this->actor(['configure']), $this->policyInput());
        $ticket = $this->ticket();
        $actor = $this->actor(['view', 'view_all', 'resolve', 'comment']);
        $service = app(SupportTicketService::class);
        $service->comment($actor, $ticket->uuid, ['client_operation_uuid' => (string) Str::uuid(), 'body' => 'Revisión iniciada.']);
        $service->transition($actor, $ticket->uuid, ['client_operation_uuid' => (string) Str::uuid(), 'status' => 'RESOLVED', 'resolution' => 'Validado.']);
        $this->travel(2)->hours();
        app(SupportSlaService::class)->scan();
        $this->assertFalse($ticket->fresh()->response_breached);
        $this->assertFalse($ticket->fresh()->resolution_breached);
        $this->assertSame(0, SupportTicketEvent::query()->where('kind', 'support.sla.breached')->count());
    }

    public function test_sla_scan_respects_total_batch_and_future_policy_is_not_effective(): void
    {
        $this->freezeTime();
        $actor = $this->actor(['configure']);
        app(SupportPolicyService::class)->publish($actor, [...$this->policyInput(), 'valid_from' => now()->addDay()->toIso8601String()]);
        $this->assertNull($this->ticket()->response_due_at);
        app(SupportPolicyService::class)->publish($actor, $this->policyInput());
        $this->ticket();
        $this->ticket();
        $this->travel(11)->minutes();
        $result = app(SupportSlaService::class)->scan(1);
        $this->assertSame(1, $result['processed']);
        $this->assertSame(1, SupportTicket::query()->where('response_breached', true)->count());
    }

    public function test_offset_policy_dates_are_normalized_to_utc(): void
    {
        $this->freezeTime();
        $input = $this->policyInput();
        $input['valid_from'] = now('UTC')->subMinute()->setTimezone('America/Mexico_City')->toIso8601String();
        $input['valid_until'] = now('UTC')->addHour()->setTimezone('America/Mexico_City')->toIso8601String();
        app(SupportPolicyService::class)->publish($this->actor(['configure']), $input);
        $policy = SupportPolicyVersion::query()->firstOrFail();
        $this->assertSame(now('UTC')->subMinute()->format('Y-m-d H:i:s'), $policy->valid_from->utc()->format('Y-m-d H:i:s'));
        $this->assertNotNull($this->ticket()->response_due_at);
    }

    public function test_late_response_is_recorded_before_close_and_closed_history_is_not_mutated(): void
    {
        $this->freezeTime();
        app(SupportPolicyService::class)->publish($this->actor(['configure']), $this->policyInput());
        $ticket = $this->ticket();
        $actor = $this->actor(['manage']);
        $this->travel(70)->minutes();
        $service = app(SupportTicketService::class);
        $service->comment($actor, $ticket->uuid, ['client_operation_uuid' => (string) Str::uuid(), 'body' => 'Respuesta tardía registrada.']);
        $service->transition($actor, $ticket->uuid, ['client_operation_uuid' => (string) Str::uuid(), 'status' => 'RESOLVED', 'resolution' => 'Recuperado.']);
        $service->transition($actor, $ticket->uuid, ['client_operation_uuid' => (string) Str::uuid(), 'status' => 'CLOSED']);
        $this->assertTrue($ticket->fresh()->response_breached);
        $this->assertTrue($ticket->fresh()->resolution_breached);
        $count = $ticket->events()->count();
        app(SupportSlaService::class)->scan();
        $this->assertSame($count, $ticket->events()->count());
    }

    private function policyInput(): array
    {
        return [
            'client_operation_uuid' => (string) Str::uuid(), 'label' => 'DEMO test policy', 'is_demo' => true, 'active' => true,
            'valid_from' => now()->subMinute()->toIso8601String(), 'valid_until' => null,
            'payload' => ['sla' => ['enabled' => true, 'response_minutes' => 10, 'resolution_minutes' => 60, 'warning_minutes' => 5]],
        ];
    }

    private function ticket(array $overrides = []): SupportTicket
    {
        $response = app(SupportTicketService::class)->create(SupportActor::system(), array_replace([
            'client_operation_uuid' => (string) Str::uuid(), 'vending_machine_id' => $this->machine()->id,
            'source' => 'SYSTEM', 'title' => 'Prueba SLA', 'description' => 'Prueba sin datos sensibles.', 'category' => 'OTHER',
        ], $overrides));

        return SupportTicket::query()->where('uuid', $response['ticket']['uuid'])->firstOrFail();
    }

    private function actor(array $actions): SupportActor
    {
        $user = User::factory()->create(['estatus' => true]);
        $role = Role::query()->create(['name' => 'SLA-'.Str::uuid()]);
        foreach ($actions as $action) {
            $permission = Permission::query()->firstOrCreate(['module' => 'support', 'action' => $action], ['name' => 'support-'.$action]);
            $role->permissions()->attach($permission);
        }
        $user->roles()->attach($role);

        return SupportActor::user($user);
    }
}
