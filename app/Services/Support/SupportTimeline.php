<?php

namespace App\Services\Support;

use App\Models\SupportTicket;
use App\Models\SupportTicketEvent;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class SupportTimeline
{
    public function append(SupportTicket $ticket, SupportActor $actor, string $kind, ?string $body = null, array $metadata = [], bool $public = true): SupportTicketEvent
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Support events require the aggregate transaction.');
        }
        $cursor = DB::table('support_runtime_cursors')->where('key', 'timeline_sequence')->lockForUpdate()->first();
        $sequence = (int) $cursor->value + 1;
        DB::table('support_runtime_cursors')->where('key', 'timeline_sequence')->update(['value' => $sequence]);
        $event = SupportTicketEvent::query()->create([
            'uuid' => (string) Str::uuid(), 'sequence' => $sequence, 'ticket_id' => $ticket->id,
            'kind' => $kind, 'actor_kind' => $actor->kind, 'actor_id' => $actor->id ?: null,
            'body' => $body, 'metadata' => $metadata, 'public' => $public, 'created_at' => now('UTC'),
        ]);
        // No comment body, file path, location, token or payload enters existing audit.
        AuditLogger::log($kind, $ticket, 'Cambio de soporte', [
            'support_event_uuid' => $event->uuid, 'support_actor_kind' => $actor->kind,
            'support_actor_id' => $actor->id, 'device_id' => $actor->kind === 'device' ? $actor->id : null,
        ]);
        // Delivery cannot undo the committed ticket. The persistent cursor remains
        // the recovery mechanism when this bounded local projection is interrupted.
        DB::afterCommit(static function (): void {
            try {
                app(SupportNotificationService::class)->publish(20, 100);
            } catch (\Throwable) {
                // No payload or credential logging; support:process-events retries.
            }
        });

        return $event;
    }
}
