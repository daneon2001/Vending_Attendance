<?php

namespace Tests\Feature\Support;

use App\Models\Device;
use App\Models\SupportCorrelation;
use App\Models\SupportPolicyVersion;
use App\Models\SupportTicket;
use App\Models\SupportTicketEvent;
use App\Models\VendingMachine;
use App\Services\Support\SupportActor;
use App\Services\Support\SupportAutomationService;
use App\Services\Support\SupportTicketService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Api\V1\VendingDeviceApiTestCase;

class SupportAutomationTest extends VendingDeviceApiTestCase
{
    public function test_disabled_automation_does_not_write_or_move_cursor(): void
    {
        $this->device($this->machine(), ['pending_events_count' => 200]);
        $result = app(SupportAutomationService::class)->scan();
        $this->assertFalse($result['enabled']);
        $this->assertDatabaseCount('support_correlations', 0);
        $this->assertDatabaseCount('support_tickets', 0);
        $this->assertSame(0, (int) DB::table('support_runtime_cursors')->where('key', 'fleet_device_id')->value('value'));
    }

    public function test_one_hundred_equivalent_signals_create_one_ticket_without_poll_audit(): void
    {
        $machine = $this->machine();
        $this->device($machine, ['pending_events_count' => 200]);
        $this->policy([$machine->id]);
        $service = app(SupportAutomationService::class);
        $service->scan();
        $audits = DB::table('audit_logs')->count();
        for ($i = 0; $i < 100; $i++) {
            $service->scan();
        }
        $this->assertDatabaseCount('support_tickets', 1);
        $this->assertDatabaseCount('support_correlations', 1);
        $this->assertDatabaseCount('support_ticket_events', 2);
        $this->assertSame($audits, DB::table('audit_logs')->count());
        $ticket = SupportTicket::query()->firstOrFail();
        $this->assertSame('HIGH', $ticket->severity);
        $this->assertSame('NORMAL', $ticket->priority);
        $this->assertSame('AUTOMATED_ALERT', $ticket->source);
    }

    public function test_persistence_is_required_and_unknown_resets_continuity(): void
    {
        $this->freezeTime();
        $machine = $this->machine();
        $device = $this->device($machine, ['pending_events_count' => 200]);
        $this->policy([$machine->id], ['persistence_seconds' => 120]);
        $service = app(SupportAutomationService::class);
        $service->scan();
        $this->assertDatabaseCount('support_tickets', 0);
        $this->travel(100)->seconds();
        $device->update(['last_heartbeat_at' => now()->subHour()]);
        $service->scan();
        $this->travel(100)->seconds();
        $device->update(['last_heartbeat_at' => now()]);
        $service->scan();
        $this->assertDatabaseCount('support_tickets', 0);
        $this->travel(120)->seconds();
        $device->update(['last_heartbeat_at' => now()]);
        $service->scan();
        $this->assertDatabaseCount('support_tickets', 1);
    }

    public function test_offline_does_not_recover_outbox_and_true_recovery_is_once_without_close(): void
    {
        $this->freezeTime();
        $machine = $this->machine();
        $device = $this->device($machine, ['pending_events_count' => 200]);
        $this->policy([$machine->id]);
        $service = app(SupportAutomationService::class);
        $service->scan();
        $device->update(['last_heartbeat_at' => now()->subHour()]);
        $service->scan();
        $this->assertSame('UNKNOWN', SupportCorrelation::query()->firstOrFail()->signal_state);
        $this->assertSame(0, SupportTicketEvent::query()->where('kind', 'support.ticket.recovery')->count());
        $device->update(['last_heartbeat_at' => now(), 'pending_events_count' => 0]);
        for ($i = 0; $i < 5; $i++) {
            $service->scan();
        }
        $this->assertSame(1, SupportTicketEvent::query()->where('kind', 'support.ticket.recovery')->count());
        $this->assertSame('OPEN', SupportTicket::query()->firstOrFail()->status);
    }

    public function test_closed_ticket_needs_recovery_and_cooldown_before_new_lifecycle(): void
    {
        $this->freezeTime();
        $machine = $this->machine();
        $device = $this->device($machine, ['pending_events_count' => 200]);
        $this->policy([$machine->id], ['cooldown_seconds' => 600]);
        $service = app(SupportAutomationService::class);
        $service->scan();
        $ticket = SupportTicket::query()->firstOrFail();
        $tickets = app(SupportTicketService::class);
        $tickets->transition(SupportActor::system(), $ticket->uuid, [
            'client_operation_uuid' => (string) Str::uuid(), 'status' => 'RESOLVED', 'resolution' => 'Prueba técnica terminada.',
        ]);
        $tickets->transition(SupportActor::system(), $ticket->uuid, ['client_operation_uuid' => (string) Str::uuid(), 'status' => 'CLOSED']);
        $events = $ticket->events()->count();
        $this->travel(700)->seconds();
        $device->update(['last_heartbeat_at' => now()]);
        $service->scan();
        $this->assertDatabaseCount('support_tickets', 1);
        $this->assertSame($events, $ticket->events()->count());
        $device->update(['pending_events_count' => 0]);
        $service->scan();
        $this->travel(1)->seconds();
        $device->update(['pending_events_count' => 200]);
        $service->scan();
        $this->assertDatabaseCount('support_tickets', 2);
        $this->assertSame($events, $ticket->events()->count());
    }

    public function test_policy_scope_reserved_machine_and_cursor_are_bounded(): void
    {
        $allowed = $this->machine();
        $alsoAllowed = $this->machine();
        $other = $this->machine();
        $reserved = $this->machine();
        $reserved->update(['sybi_id' => '683', 'machine_code' => '7', 'source' => 'SYBI']);
        $first = $this->device($allowed, ['pending_events_count' => 200]);
        $this->device($alsoAllowed, ['pending_events_count' => 200]);
        $this->device($other, ['pending_events_count' => 200]);
        $this->device($reserved, ['pending_events_count' => 200]);
        $this->policy([$allowed->id, $alsoAllowed->id, $reserved->id]);
        $service = app(SupportAutomationService::class);
        $page = $service->scan(1);
        $this->assertSame(1, $page['processed']);
        $this->assertSame($first->id, $page['next_cursor']);
        $service->scan(1);
        $service->scan(100); // Include the reserved machine, not only the first two allowed rows.
        $this->assertDatabaseCount('support_tickets', 2);
        $this->assertSame([$allowed->id, $alsoAllowed->id], SupportTicket::query()->distinct()->orderBy('vending_machine_id')->pluck('vending_machine_id')->all());
    }

    public function test_expired_future_and_empty_demo_scope_cannot_generate(): void
    {
        $machine = $this->machine();
        $this->device($machine, ['pending_events_count' => 200]);
        $this->policy([$machine->id], [], ['valid_until' => now()->subSecond()]);
        $this->assertFalse(app(SupportAutomationService::class)->scan()['enabled']);
        $this->policy([$machine->id], [], ['version' => 2, 'valid_from' => now()->addDay()]);
        $this->assertFalse(app(SupportAutomationService::class)->scan()['enabled']);
        $this->assertDatabaseCount('support_tickets', 0);
        $this->policy([], [], ['version' => 3]);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(SupportAutomationService::class)->scan();
    }

    private function policy(array $machineIds, array $rule = [], array $attributes = []): SupportPolicyVersion
    {
        config(['support.automation.enabled' => true]);

        return SupportPolicyVersion::query()->create(array_replace([
            'version' => 1, 'label' => 'DEMO test only', 'is_demo' => true, 'active' => true,
            'valid_from' => now()->subMinute(), 'valid_until' => null,
            'payload' => ['automation' => ['enabled' => true, 'rules' => [array_replace([
                'key' => 'OUTBOX_HIGH', 'enabled' => true, 'source' => 'FLEET',
                'persistence_seconds' => 0, 'cooldown_seconds' => 600,
                'category' => 'APPLICATION', 'severity' => 'HIGH', 'priority' => 'NORMAL', 'machine_ids' => $machineIds,
            ], $rule)]]],
        ], $attributes));
    }

    private function device(VendingMachine $machine, array $attributes = []): Device
    {
        return Device::query()->forceCreate(array_replace([
            'uuid' => (string) Str::uuid(), 'device_serial' => 'AUTO-'.Str::random(12),
            'vending_machine_id' => $machine->id, 'status' => 'ACTIVE', 'is_active' => true,
            'platform' => 'android', 'app_version' => '1.0.0', 'last_heartbeat_at' => now(),
            'last_seen_at' => now(), 'pending_events_count' => 0, 'clock_drift_seconds' => 0,
            'config_version_applied' => 1, 'employee_manifest_version_applied' => 1,
        ], $attributes));
    }
}
