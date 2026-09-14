<?php

namespace App\Services\Support;

use App\Models\SupportTicket;
use App\Models\SupportTicketEvent;
use App\Models\User;
use App\Models\VendingSupportActivity;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SupportNotificationService
{
    private const EVENTS = [
        'support.ticket.created', 'support.ticket.assigned', 'support.comment.created',
        'support.ticket.status_changed', 'support.ticket.resolved', 'support.ticket.closed',
        'support.sla.warning', 'support.sla.breached',
        'support_activity.note_added', 'support_activity.evidence_added',
    ];

    public function __construct(private readonly SupportAccess $access) {}

    /** The event cursor advances only after its bounded recipient pages are exhausted. */
    public function publish(?int $maxEvents = null, ?int $maxRecipients = null): array
    {
        $eventLimit = max(1, min($maxEvents ?? 100, (int) config('support.notifications.max_event_batch', 250)));
        $recipientLimit = max(1, min($maxRecipients ?? 250, (int) config('support.notifications.max_recipient_batch', 500)));

        return DB::transaction(function () use ($eventLimit, $recipientLimit): array {
            // Same global projector lock and budgets. Alternate priority to avoid starvation.
            DB::table('support_runtime_cursors')->where('key', 'notification_sequence')->lockForUpdate()->firstOrFail();
            DB::table('support_runtime_cursors')->insertOrIgnore(['key' => 'notification_stream_turn', 'value' => 0]);
            $turn = (int) DB::table('support_runtime_cursors')->where('key', 'notification_stream_turn')->value('value');
            $result = ['events' => 0, 'notifications' => 0, 'recipient_rows' => 0];
            $started = hrtime(true);
            foreach ($turn % 2 ? ['publishActivities', 'publishTickets'] : ['publishTickets', 'publishActivities'] as $method) {
                if ($result['events'] >= $eventLimit || $result['recipient_rows'] >= $recipientLimit || $this->expired($started)) {
                    break;
                }
                $page = $this->{$method}($eventLimit - $result['events'], $recipientLimit - $result['recipient_rows'], $started);
                foreach ($page as $key => $value) {
                    $result[$key] += $value;
                }
            }
            DB::table('support_runtime_cursors')->where('key', 'notification_stream_turn')->update(['value' => 1 - ($turn % 2)]);

            return $result;
        }, 3);
    }

    private function expired(int $started): bool
    {
        return (hrtime(true) - $started) / 1_000_000_000 >= max(1, (int) config('support.notifications.max_duration_seconds', 15));
    }

    private function publishTickets(int $eventLimit, int $recipientLimit, int $started): array
    {

        return DB::transaction(function () use ($eventLimit, $recipientLimit, $started): array {
            $cursor = DB::table('support_runtime_cursors')->where('key', 'notification_sequence')->lockForUpdate()->firstOrFail();
            DB::table('support_runtime_cursors')->insertOrIgnore(['key' => 'notification_recipient_id', 'value' => 0]);
            $recipientCursor = (int) DB::table('support_runtime_cursors')->where('key', 'notification_recipient_id')->value('value');
            $sequence = (int) $cursor->value;
            $result = ['events' => 0, 'notifications' => 0, 'recipient_rows' => 0];
            while ($result['events'] < $eventLimit && $result['recipient_rows'] < $recipientLimit) {
                if ($this->expired($started)) {
                    break;
                }
                $event = SupportTicketEvent::query()->with('ticket')->where('sequence', '>', $sequence)->orderBy('sequence')->first();
                if (! $event) {
                    break;
                }
                $ticket = $event->ticket;
                $remaining = $recipientLimit - $result['recipient_rows'];
                $recipients = in_array($event->kind, self::EVENTS, true) ? User::query()
                    ->where('estatus', true)->where('id', '>', $recipientCursor)
                    ->where(function ($query) use ($ticket): void {
                        $query->whereIn('id', array_filter([$ticket->reporter_user_id, $ticket->assignee_id]))
                            ->orWhereHas('roles.permissions', fn ($query) => $query->where('module', 'support')->whereIn('action', ['view_all', 'manage']));
                    })->with('roles.permissions')->orderBy('id')->limit($remaining + 1)->get() : collect();
                foreach ($recipients->take($remaining) as $user) {
                    $recipientCursor = (int) $user->id;
                    $result['recipient_rows']++;
                    $actor = SupportActor::user($user);
                    if (! $event->public && ! $user->hasPermission('support', 'view_all')) {
                        continue;
                    }
                    try {
                        $this->access->authorize($actor, 'read', $ticket);
                    } catch (HttpException) {
                        continue;
                    }
                    $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'support:'.$event->uuid.':user:'.$user->id)->toString();
                    $result['notifications'] += DB::table('notifications')->insertOrIgnore([
                        'id' => $id, 'type' => 'support.ticket.event', 'notifiable_type' => $user->getMorphClass(), 'notifiable_id' => $user->id,
                        'data' => json_encode([
                            'ticket_uuid' => $ticket->uuid, 'folio' => $ticket->folio, 'event_uuid' => $event->uuid, 'kind' => $event->kind,
                        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                        'created_at' => now('UTC'), 'updated_at' => now('UTC'),
                    ]);
                }
                if ($recipients->count() > $remaining) {
                    break;
                }
                $sequence = (int) $event->sequence;
                $recipientCursor = 0;
                $result['events']++;
            }
            DB::table('support_runtime_cursors')->where('key', 'notification_sequence')->update(['value' => $sequence]);
            DB::table('support_runtime_cursors')->where('key', 'notification_recipient_id')->update(['value' => $recipientCursor]);

            return $result;
        }, 3);
    }

    private function publishActivities(int $eventLimit, int $recipientLimit, int $started): array
    {
        $result = ['events' => 0, 'notifications' => 0, 'recipient_rows' => 0];
        if (! Schema::hasTable('vending_support_activity_events')) {
            return $result;
        }
        foreach (['notification_activity_id', 'notification_activity_recipient_id'] as $key) {
            DB::table('support_runtime_cursors')->insertOrIgnore(['key' => $key, 'value' => 0]);
        }
        $cursor = (int) DB::table('support_runtime_cursors')->where('key', 'notification_activity_id')->value('value');
        $recipient = (int) DB::table('support_runtime_cursors')->where('key', 'notification_activity_recipient_id')->value('value');
        while ($result['events'] < $eventLimit && $result['recipient_rows'] < $recipientLimit && ! $this->expired($started)) {
            $event = DB::table('vending_support_activity_events')->where('id', '>', $cursor)->orderBy('id')->first();
            if (! $event) {
                break;
            }
            $activity = VendingSupportActivity::query()->find($event->activity_id);
            $remaining = $recipientLimit - $result['recipient_rows'];
            // Assignment and terminal outcomes are actionable. Start and contributions stay in timeline.
            $recipients = $activity && in_array($event->kind, ['assigned', 'completed', 'cancelled'], true)
                ? User::query()->where('estatus', true)->where('id', '>', $recipient)->where('id', '!=', $event->user_id)
                    ->where(function ($q) use ($activity): void {
                        $q->where('employee_id', $activity->employee_id)
                            ->orWhereIn('id', array_filter([$activity->created_by_user_id, $activity->assigned_by_user_id]))
                            ->orWhereHas('roles.permissions', fn ($p) => $p->where('module', 'support')->whereIn('action', ['assign', 'manage', 'view_all']));
                    })->with('roles.permissions')->orderBy('id')->limit($remaining + 1)->get() : collect();
            foreach ($recipients->take($remaining) as $user) {
                $recipient = (int) $user->id;
                $result['recipient_rows']++;
                if (! app(SupportActivityWebQueries::class)->notificationScope($user)->whereKey($activity->id)->exists()
                    && ! app(FieldSupportActivityAccess::class)->notificationScope($user)->whereKey($activity->id)->exists()) {
                    continue;
                }
                $eventUuid = Uuid::uuid5(Uuid::NAMESPACE_URL, 'support:activity:'.$activity->uuid.':event:'.$event->id)->toString();
                $result['notifications'] += DB::table('notifications')->insertOrIgnore([
                    'id' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'support:'.$eventUuid.':user:'.$user->id)->toString(),
                    'type' => 'support.activity.event', 'notifiable_type' => $user->getMorphClass(), 'notifiable_id' => $user->id,
                    'data' => json_encode(['activity_uuid' => $activity->uuid,
                        'folio' => 'ACT-'.str_pad((string) $activity->id, 6, '0', STR_PAD_LEFT),
                        'event_uuid' => $eventUuid, 'event_id' => $event->id, 'kind' => 'support_activity.'.$event->kind], JSON_THROW_ON_ERROR),
                    'created_at' => $event->occurred_at, 'updated_at' => now('UTC'),
                ]);
            }
            if ($recipients->count() > $remaining) {
                break;
            }
            $cursor = (int) $event->id;
            $recipient = 0;
            $result['events']++;
        }
        DB::table('support_runtime_cursors')->where('key', 'notification_activity_id')->update(['value' => $cursor]);
        DB::table('support_runtime_cursors')->where('key', 'notification_activity_recipient_id')->update(['value' => $recipient]);

        return $result;
    }

    public function feed(SupportActor $actor, int $limit = 50, bool $field = false): array
    {
        $actor = $this->freshUser($actor);
        $query = $this->authorizedNotifications($actor, $field);
        $notifications = (clone $query)->orderByDesc('created_at')->orderByDesc('id')
            ->limit(max(1, min($limit, (int) config('support.pagination.max', 100))))->get()
            ->map(static function ($row): array {
                $data = json_decode($row->data, true, 512, JSON_THROW_ON_ERROR);
                $activity = $row->type === 'support.activity.event';

                return [
                    'id' => $row->id, 'ticket_uuid' => $data['ticket_uuid'] ?? null,
                    'activity_uuid' => $data['activity_uuid'] ?? null, 'folio' => $data['folio'],
                    'event_uuid' => $data['event_uuid'], 'kind' => $data['kind'],
                    'created_at' => $activity ? Carbon::parse($row->created_at, 'UTC')->toISOString() : $row->created_at,
                    'read_at' => $activity && $row->read_at ? Carbon::parse($row->read_at, 'UTC')->toISOString() : $row->read_at,
                ];
            })->all();

        return ['notifications' => $notifications, 'unread_count' => (clone $query)->whereNull('read_at')->count()];
    }

    public function markRead(SupportActor $actor, string $notificationUuid, bool $field = false): void
    {
        $actor = $this->freshUser($actor);
        abort_unless(Str::isUuid($notificationUuid), 404);
        $query = $this->authorizedNotifications($actor, $field)->where('id', $notificationUuid);
        abort_unless((clone $query)->exists(), 404, 'Notificación no disponible.');
        $query->whereNull('read_at')->update(['read_at' => now('UTC'), 'updated_at' => now('UTC')]);
    }

    private function freshUser(SupportActor $actor): SupportActor
    {
        abort_unless($actor->kind === 'user', 403);
        $user = User::query()->with('roles.permissions')->find($actor->id);
        abort_unless($user, 403);
        $fresh = SupportActor::user($user);
        $this->access->authorize($fresh, 'read');

        return $fresh;
    }

    private function authorizedNotifications(SupportActor $actor, bool $field): Builder
    {
        $tickets = $this->access->scope($actor, SupportTicket::query())->select('uuid');
        $events = SupportTicketEvent::query()->select('uuid');
        if (! $actor->model->hasPermission('support', 'view_all')) {
            $events->where('public', true);
        }

        return DB::table('notifications')
            ->where('notifiable_type', $actor->model->getMorphClass())->where('notifiable_id', $actor->id)
            ->where(function (Builder $query) use ($actor, $tickets, $events, $field): void {
                $query->whereRaw('1 = 0');
                if (! $field) {
                    $query->orWhere(fn (Builder $q) => $q->where('type', 'support.ticket.event')
                        ->whereIn('data->ticket_uuid', $tickets)->whereIn('data->event_uuid', $events));
                }
                if (Schema::hasTable('vending_support_activity_events')) {
                    $scope = $field ? app(FieldSupportActivityAccess::class)->notificationScope($actor->model)
                        : app(SupportActivityWebQueries::class)->notificationScope($actor->model);
                    $query->orWhere(fn (Builder $q) => $q->where('type', 'support.activity.event')
                        ->whereIn('data->activity_uuid', (clone $scope)->select('uuid'))
                        ->whereIn('data->event_id', DB::table('vending_support_activity_events')
                            ->whereIn('activity_id', $scope->select('id'))->select('id')));
                }
            });
    }
}
