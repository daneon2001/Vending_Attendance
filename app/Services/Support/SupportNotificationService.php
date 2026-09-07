<?php

namespace App\Services\Support;

use App\Models\SupportTicket;
use App\Models\SupportTicketEvent;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SupportNotificationService
{
    private const EVENTS = [
        'support.ticket.created', 'support.ticket.assigned', 'support.comment.created',
        'support.ticket.status_changed', 'support.ticket.resolved', 'support.ticket.closed',
        'support.sla.warning', 'support.sla.breached',
    ];

    public function __construct(private readonly SupportAccess $access) {}

    /** The event cursor advances only after its bounded recipient pages are exhausted. */
    public function publish(?int $maxEvents = null, ?int $maxRecipients = null): array
    {
        $eventLimit = max(1, min($maxEvents ?? 100, (int) config('support.notifications.max_event_batch', 250)));
        $recipientLimit = max(1, min($maxRecipients ?? 250, (int) config('support.notifications.max_recipient_batch', 500)));

        return DB::transaction(function () use ($eventLimit, $recipientLimit): array {
            $cursor = DB::table('support_runtime_cursors')->where('key', 'notification_sequence')->lockForUpdate()->firstOrFail();
            DB::table('support_runtime_cursors')->insertOrIgnore(['key' => 'notification_recipient_id', 'value' => 0]);
            $recipientCursor = (int) DB::table('support_runtime_cursors')->where('key', 'notification_recipient_id')->value('value');
            $sequence = (int) $cursor->value;
            $result = ['events' => 0, 'notifications' => 0, 'recipient_rows' => 0];
            $started = hrtime(true);
            while ($result['events'] < $eventLimit && $result['recipient_rows'] < $recipientLimit) {
                if ((hrtime(true) - $started) / 1_000_000_000 >= max(1, (int) config('support.notifications.max_duration_seconds', 15))) {
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

    public function feed(SupportActor $actor, int $limit = 50): array
    {
        $actor = $this->freshUser($actor);
        $query = $this->authorizedNotifications($actor);
        $notifications = (clone $query)->orderByDesc('created_at')->orderByDesc('id')
            ->limit(max(1, min($limit, (int) config('support.pagination.max', 100))))->get()
            ->map(static function ($row): array {
                $data = json_decode($row->data, true, 512, JSON_THROW_ON_ERROR);

                return [
                    'id' => $row->id, 'ticket_uuid' => $data['ticket_uuid'], 'folio' => $data['folio'],
                    'event_uuid' => $data['event_uuid'], 'kind' => $data['kind'],
                    'created_at' => $row->created_at, 'read_at' => $row->read_at,
                ];
            })->all();

        return ['notifications' => $notifications, 'unread_count' => (clone $query)->whereNull('read_at')->count()];
    }

    public function markRead(SupportActor $actor, string $notificationUuid): void
    {
        $actor = $this->freshUser($actor);
        abort_unless(Str::isUuid($notificationUuid), 404);
        $query = $this->authorizedNotifications($actor)->where('id', $notificationUuid);
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

    private function authorizedNotifications(SupportActor $actor): Builder
    {
        $tickets = $this->access->scope($actor, SupportTicket::query())->select('uuid');
        $events = SupportTicketEvent::query()->select('uuid');
        if (! $actor->model->hasPermission('support', 'view_all')) {
            $events->where('public', true);
        }

        return DB::table('notifications')->where('type', 'support.ticket.event')
            ->where('notifiable_type', $actor->model->getMorphClass())->where('notifiable_id', $actor->id)
            ->whereIn('data->ticket_uuid', $tickets)->whereIn('data->event_uuid', $events);
    }
}
