<?php

namespace Tests\Feature\Support;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Support\SupportActivityService;
use App\Services\Support\SupportActor;
use App\Services\Support\SupportNotificationService;
use App\Services\Support\SupportTicketService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Api\V1\VendingDeviceApiTestCase;

class SupportActivityNotificationsTest extends VendingDeviceApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-10T18:00:00Z'));
    }

    protected function tearDown(): void
    {
        $this->assertDatabaseCount('attendance_logs', 0);
        $this->assertDatabaseCount('vending_attendance_events', 0);
        $this->travelBack();
        parent::tearDown();
    }

    private function person(array $permissions): User
    {
        $employee = $this->employee(['source' => 'FORTIA', 'source_external_id' => (string) Str::uuid()]);
        $user = User::factory()->create(['estatus' => true]);
        $user->employee()->associate($employee);
        $user->save();
        $role = Role::create(['name' => 'Notification fixture '.Str::uuid()]);
        foreach ($permissions as $action) {
            $role->permissions()->attach(Permission::firstOrCreate(['module' => 'support', 'action' => $action], ['name' => 'support.'.$action]));
        }
        $user->roles()->attach($role);

        return $user;
    }

    private function scenario(bool $linked = false): array
    {
        $manager = $this->person(['view', 'assign', 'report']);
        $worker = $this->person(['view', 'resolve']);
        $outsider = $this->person(['view', 'assign', 'view_all']);
        $machine = $this->machine();
        $this->assignment($machine, $manager->employee, ['maintenance_allowed' => true]);
        $assignment = $this->assignment($machine, $worker->employee, ['maintenance_allowed' => true]);
        $this->geofence($machine);
        $this->actingAs($manager);
        $ticket = $linked ? app(SupportTicketService::class)->create(SupportActor::user($manager), [
            'client_operation_uuid' => (string) Str::uuid(), 'vending_machine_id' => $machine->id,
            'title' => 'Fixture ticket', 'description' => 'Private ticket body', 'category' => 'OTHER',
        ])['ticket'] : null;
        $input = ['client_operation_uuid' => (string) Str::uuid(), 'vending_machine_id' => $machine->id,
            'employee_id' => $worker->employee_id, 'activity_type' => 'MAINTENANCE', 'title' => 'Private fixture title',
            'support_ticket_uuid' => $ticket['uuid'] ?? null];
        $activity = app(SupportActivityService::class)->create($input);

        return compact('manager', 'worker', 'outsider', 'assignment', 'activity', 'input', 'ticket');
    }

    private function feed(User $user): array
    {
        return app(SupportNotificationService::class)->feed(SupportActor::user($user));
    }

    public function test_independent_assignment_after_commit_without_broadcast_self_notice_or_sensitive_payload(): void
    {
        $s = $this->scenario();
        $this->assertDatabaseCount('support_tickets', 0);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(0, $this->feed($s['manager'])['unread_count']);
        $this->assertSame(0, $this->feed($s['outsider'])['unread_count']);
        $feed = $this->feed($s['worker']);
        $this->assertSame(1, $feed['unread_count']);
        $this->assertSame($s['activity']->uuid, $feed['notifications'][0]['activity_uuid']);
        $this->assertSame('support_activity.assigned', $feed['notifications'][0]['kind']);
        $this->assertSame('2026-09-10T18:00:00.000000Z', $feed['notifications'][0]['created_at']);
        $this->assertNull($feed['notifications'][0]['ticket_uuid']);
        $this->assertStringNotContainsString('Private', json_encode($feed));
        $this->actingAs($s['worker'])->getJson('/support/notifications')->assertOk()->assertJsonPath('unread_count', 1);
    }

    public function test_same_operation_and_replayed_cursor_do_not_duplicate_or_reopen_read_notifications(): void
    {
        $s = $this->scenario();
        $service = app(SupportNotificationService::class);
        $id = $this->feed($s['worker'])['notifications'][0]['id'];
        $service->markRead(SupportActor::user($s['worker']), $id);
        $read = DB::table('notifications')->where('id', $id)->value('read_at');
        app(SupportActivityService::class)->create($s['input']);
        DB::table('support_runtime_cursors')->whereIn('key', ['notification_activity_id', 'notification_activity_recipient_id'])->update(['value' => 0]);
        $service->publish();
        $service->publish();
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('vending_support_activity_events', 2);
        $this->assertSame($read, DB::table('notifications')->where('id', $id)->value('read_at'));
        $this->assertSame(0, $this->feed($s['worker'])['unread_count']);
    }

    public function test_completed_and_cancelled_notify_coordination_but_start_does_not_spam(): void
    {
        $s = $this->scenario();
        $this->actingAs($s['worker']);
        $domain = app(SupportActivityService::class);
        $domain->start($s['activity']->uuid, ['latitude' => 19.4326, 'longitude' => -99.1332, 'accuracy_m' => 5, 'captured_at' => now('UTC')->toISOString()]);
        $this->assertDatabaseCount('notifications', 1);
        $domain->complete($s['activity']->uuid);
        $this->assertDatabaseCount('notifications', 2);
        $this->assertSame('support_activity.completed', $this->feed($s['manager'])['notifications'][0]['kind']);
        $this->actingAs($s['manager']);
        $other = $domain->create(array_replace($s['input'], ['client_operation_uuid' => (string) Str::uuid()]));
        $domain->cancel($other->uuid, 'Synthetic cancellation');
        $this->assertDatabaseCount('notifications', 4);
        $this->assertContains('support_activity.cancelled', array_column($this->feed($s['worker'])['notifications'], 'kind'));
    }

    public function test_linked_ticket_notifications_and_status_are_preserved_independently(): void
    {
        $s = $this->scenario(true);
        $before = DB::table('support_tickets')->first();
        $this->assertGreaterThan(0, DB::table('notifications')->where('type', 'support.ticket.event')->count());
        $this->assertSame(1, DB::table('notifications')->where('type', 'support.activity.event')->count());
        app(SupportNotificationService::class)->publish();
        $this->assertSame((array) $before, (array) DB::table('support_tickets')->first());
        $this->assertDatabaseCount('support_tickets', 1);
        // No ticket permission is inferred from activity ownership.
        $this->assertSame(1, $this->feed($s['worker'])['unread_count']);
    }

    public function test_revoked_assignment_permission_identity_and_other_recipient_fail_closed(): void
    {
        $s = $this->scenario();
        $id = $this->feed($s['worker'])['notifications'][0]['id'];
        $this->actingAs($s['outsider'])->postJson('/support/notifications/'.$id.'/read')->assertNotFound();
        DB::table('employee_machine_assignments')->where('id', $s['assignment']->id)->update(['valid_until' => now()->subDay()]);
        $this->assertSame(0, $this->feed($s['worker'])['unread_count']);
        $this->actingAs($s['worker'])->postJson('/support/notifications/'.$id.'/read')->assertNotFound();
        $this->assertNull(DB::table('notifications')->where('id', $id)->value('read_at'));
        $s['worker']->roles()->detach();
        $this->getJson('/support/notifications')->assertForbidden();
    }

    public function test_recipient_budget_resumes_and_transaction_rollback_has_no_notifications(): void
    {
        $s = $this->scenario();
        DB::table('notifications')->delete();
        DB::table('support_runtime_cursors')->whereIn('key', ['notification_activity_id', 'notification_activity_recipient_id'])->update(['value' => 0]);
        foreach (range(1, 10) as $i) {
            $page = app(SupportNotificationService::class)->publish(1, 1);
            $this->assertLessThanOrEqual(1, $page['events']);
            $this->assertLessThanOrEqual(1, $page['recipient_rows']);
        }
        $this->assertDatabaseCount('notifications', 1);
        DB::beginTransaction();
        app(SupportActivityService::class)->create(array_replace($s['input'], ['client_operation_uuid' => (string) Str::uuid()]));
        DB::rollBack();
        app(SupportNotificationService::class)->publish();
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('vending_support_activities', 1);
    }
}
