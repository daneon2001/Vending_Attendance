<?php

namespace Tests\Feature\Support;

use App\Models\Permission;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Support\DeferredExternalSupportEventDelivery;
use App\Services\Support\DeferredPushNotificationProvider;
use App\Services\Support\SupportActor;
use App\Services\Support\SupportNotificationService;
use App\Services\Support\SupportTicketService;
use App\Services\Support\SupportTimeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Api\V1\VendingDeviceApiTestCase;

class SupportNotificationsTest extends VendingDeviceApiTestCase
{
    public function test_only_authorized_recipients_receive_minimal_idempotent_notifications(): void
    {
        $reporter = $this->actor(['view', 'report']);
        $support = $this->actor(['view', 'view_all']);
        $reader = $this->actor(['view']);
        $ticket = $this->ticket($reporter);
        $service = app(SupportNotificationService::class);
        $this->assertDatabaseCount('notifications', 2); // The local after-commit hook publishes without a queue worker.
        $service->publish();
        DB::table('support_runtime_cursors')->where('key', 'notification_sequence')->update(['value' => 0]);
        $service->publish();
        $this->assertDatabaseCount('notifications', 2);
        $this->assertSame(1, $service->feed($reporter)['unread_count']);
        $this->assertSame(1, $service->feed($support)['unread_count']);
        $this->assertSame(0, $service->feed($reader)['unread_count']);
        $data = json_decode(DB::table('notifications')->first()->data, true);
        $this->assertSame(['ticket_uuid', 'folio', 'event_uuid', 'kind'], array_keys($data));
        $this->assertSame($ticket->uuid, $data['ticket_uuid']);
        $this->assertStringNotContainsString('private description', json_encode($service->feed($support)));
    }

    public function test_read_marker_is_idempotent_and_cannot_mark_another_recipient(): void
    {
        $reporter = $this->actor(['view', 'report']);
        $other = $this->actor(['view', 'view_all']);
        $this->ticket($reporter);
        $service = app(SupportNotificationService::class);
        $service->publish();
        $id = $service->feed($reporter)['notifications'][0]['id'];
        try {
            $service->markRead($other, $id);
            $this->fail('A recipient cannot update another feed.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
        $this->assertNull(DB::table('notifications')->where('id', $id)->value('read_at'));
        $service->markRead($reporter, $id);
        $first = DB::table('notifications')->where('id', $id)->value('read_at');
        $service->markRead($reporter, $id);
        $this->assertSame($first, DB::table('notifications')->where('id', $id)->value('read_at'));
        $this->assertSame(0, $service->feed($reporter)['unread_count']);
    }

    public function test_current_object_scope_is_rechecked_despite_stale_user_relations(): void
    {
        $reporter = $this->actor(['view', 'report']);
        $support = $this->actor(['view', 'view_all']);
        $this->ticket($reporter);
        $service = app(SupportNotificationService::class);
        $service->publish();
        $notification = $service->feed($support)['notifications'][0]['id'];
        $support->model->load('roles.permissions');
        $permission = Permission::query()->where('module', 'support')->where('action', 'view_all')->firstOrFail();
        $support->model->roles->first()->permissions()->detach($permission->id);
        $this->assertSame(['notifications' => [], 'unread_count' => 0], $service->feed($support));
        try {
            $service->markRead($support, $notification);
            $this->fail('Revoked scope must deny read mutation.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_disabled_user_cannot_read_or_count_prior_notifications(): void
    {
        $reporter = $this->actor(['view', 'report']);
        $this->ticket($reporter);
        $service = app(SupportNotificationService::class);
        $service->publish();
        User::query()->whereKey($reporter->id)->update(['estatus' => false]);
        try {
            $service->feed($reporter);
            $this->fail('Inactive user cannot access notifications.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_recipient_budget_resumes_without_losing_recipients_or_advancing_early(): void
    {
        $reporter = $this->actor(['view', 'report']);
        $this->actor(['view', 'view_all']);
        $this->actor(['view', 'view_all']);
        $this->ticket($reporter);
        // Reset only the disposable projection to exercise bounded recovery after the automatic hook.
        DB::table('notifications')->delete();
        DB::table('support_runtime_cursors')->whereIn('key', ['notification_sequence', 'notification_recipient_id'])->update(['value' => 0]);
        $service = app(SupportNotificationService::class);
        $first = $service->publish(10, 1);
        $this->assertSame(1, $first['notifications']);
        $this->assertSame(0, (int) DB::table('support_runtime_cursors')->where('key', 'notification_sequence')->value('value'));
        $service->publish(10, 1);
        $service->publish(10, 1);
        $this->assertDatabaseCount('notifications', 3);
        $service->publish(10, 1);
        $this->assertDatabaseCount('notifications', 3);
        $this->assertGreaterThan(0, (int) DB::table('support_runtime_cursors')->where('key', 'notification_sequence')->value('value'));
    }

    public function test_private_timeline_event_is_visible_only_to_current_support_scope(): void
    {
        $reporter = $this->actor(['view', 'report']);
        $support = $this->actor(['view', 'view_all']);
        $ticket = $this->ticket($reporter);
        DB::transaction(fn () => app(SupportTimeline::class)->append($ticket, SupportActor::system(), 'support.comment.created', 'Private content', [], false));
        $service = app(SupportNotificationService::class);
        $service->publish();
        $this->assertCount(1, $service->feed($reporter)['notifications']);
        $this->assertCount(2, $service->feed($support)['notifications']);
    }

    public function test_deferred_adapters_never_claim_delivery_or_send_http(): void
    {
        Http::fake();
        $this->assertSame('DEFERRED_CONFIGURATION', (new DeferredPushNotificationProvider)->send(['event_uuid' => (string) Str::uuid()])['status']);
        $this->assertSame('DEFERRED_CONFIGURATION', (new DeferredExternalSupportEventDelivery)->deliver(['event_uuid' => (string) Str::uuid()])['status']);
        Http::assertNothingSent();
    }

    private function ticket(SupportActor $actor): SupportTicket
    {
        $created = app(SupportTicketService::class)->create($actor, [
            'client_operation_uuid' => (string) Str::uuid(), 'vending_machine_id' => $this->machine()->id,
            'title' => 'Reporte de prueba', 'description' => 'private description', 'category' => 'OTHER',
        ]);

        return SupportTicket::query()->where('uuid', $created['ticket']['uuid'])->firstOrFail();
    }

    private function actor(array $actions): SupportActor
    {
        $user = User::factory()->create(['estatus' => true]);
        $role = Role::query()->create(['name' => 'NOTIF-'.Str::uuid()]);
        foreach ($actions as $action) {
            $permission = Permission::query()->firstOrCreate(['module' => 'support', 'action' => $action], ['name' => 'support-'.$action]);
            $role->permissions()->attach($permission);
        }
        $user->roles()->attach($role);

        return SupportActor::user($user);
    }
}
