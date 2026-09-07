<?php

namespace App\Services\Support;

use App\Models\SupportTicket;
use App\Models\SupportTicketEvent;

class SupportPresenter
{
    public function ticket(SupportTicket $ticket): array
    {
        $ticket->loadMissing(['vendingMachine', 'device', 'assignee', 'integration']);

        return [
            'uuid' => $ticket->uuid, 'folio' => $ticket->folio,
            'machine' => ['id' => $ticket->vending_machine_id, 'uuid' => $ticket->vendingMachine->uuid, 'code' => $ticket->vendingMachine->machine_code, 'name' => $ticket->vendingMachine->name],
            'device' => $ticket->device ? ['uuid' => $ticket->device->uuid, 'name' => $ticket->device->device_name] : null,
            'source' => $ticket->source, 'category' => $ticket->category, 'severity' => $ticket->severity,
            'priority' => $ticket->priority, 'status' => $ticket->status, 'title' => $ticket->title,
            'description' => $ticket->description, 'assignee' => $ticket->assignee ? ['id' => $ticket->assignee->id, 'name' => $ticket->assignee->name] : null,
            'reported_at' => $ticket->reported_at?->toISOString(), 'created_at' => $ticket->created_at?->utc()->toISOString(),
            'updated_at' => $ticket->updated_at?->utc()->toISOString(), 'resolved_at' => $ticket->resolved_at?->toISOString(),
            'closed_at' => $ticket->closed_at?->toISOString(), 'resolution' => $ticket->resolution,
            'location_available' => $ticket->location !== null, 'geofence_result' => $ticket->geofence_context['result'] ?? null,
            'response_due_at' => $ticket->response_due_at?->toISOString(), 'resolution_due_at' => $ticket->resolution_due_at?->toISOString(),
            'response_breached' => $ticket->response_breached, 'resolution_breached' => $ticket->resolution_breached,
            'external_system' => $ticket->integration?->system_key, 'external_reference' => $ticket->external_reference,
        ];
    }

    public function event(SupportTicketEvent $event, SupportActor $actor): array
    {
        $metadata = $event->metadata;
        if (str_starts_with($event->kind, 'support.evidence.')) {
            try {
                app(SupportAccess::class)->authorize($actor, 'evidence.read', $event->ticket);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                if ($exception->getStatusCode() !== 403) {
                    throw $exception;
                }
                $metadata = [];
            }
        }

        return [
            'uuid' => $event->uuid, 'sequence' => (int) $event->sequence, 'ticket_uuid' => $event->ticket->uuid,
            'folio' => $event->ticket->folio, 'kind' => $event->kind, 'body' => $event->body,
            'metadata' => $metadata, 'created_at' => $event->created_at->toISOString(),
            'ticket' => [
                'uuid' => $event->ticket->uuid, 'folio' => $event->ticket->folio,
                'title' => $event->ticket->title, 'description' => $event->ticket->description,
                'status' => $event->ticket->status, 'category' => $event->ticket->category,
                'machine' => ['uuid' => $event->ticket->vendingMachine->uuid, 'code' => $event->ticket->vendingMachine->machine_code, 'name' => $event->ticket->vendingMachine->name],
                'reported_at' => $event->ticket->reported_at->toISOString(),
                'updated_at' => $event->ticket->updated_at->utc()->toISOString(),
            ],
        ];
    }
}
