<?php

namespace App\Services\Support;

use App\Models\SupportTicket;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

class SupportSlaService
{
    public function __construct(private readonly SupportPolicyService $policies, private readonly SupportTimeline $timeline) {}

    public function initialize(SupportTicket $ticket): void
    {
        $this->requireTransaction();
        if ($ticket->policy_snapshot !== null) {
            return;
        }
        $started = Carbon::instance($ticket->created_at)->utc();
        $policy = $this->policies->effective($started);
        $sla = SupportPolicyService::validatedSla($policy?->payload['sla'] ?? ['enabled' => false]);
        $ticket->policy_version_id = $policy?->id;
        $ticket->policy_snapshot = [
            'version' => $policy ? (int) $policy->version : null, 'label' => $policy?->label,
            'is_demo' => $policy?->is_demo, 'sla' => $sla, 'started_at' => $started->toIso8601String(),
            'clock' => 'UTC_CONTINUOUS', 'waiting_pauses' => false,
        ];
        if ($sla['enabled']) {
            foreach (['response', 'resolution'] as $type) {
                $due = $started->copy()->addMinutes($sla[$type.'_minutes']);
                $ticket->{$type.'_due_at'} = $due;
                $ticket->{$type.'_warning_at'} = $due->copy()->subMinutes($sla['warning_minutes']);
            }
        }
        $ticket->save();
    }

    /** Shared with canonical mutations so late response/closure cannot hide an overdue SLA. */
    public function evaluate(SupportTicket $ticket): int
    {
        $this->requireTransaction();
        if (($ticket->terminal() && ! $ticket->isDirty('status')) || ! ($ticket->policy_snapshot['sla']['enabled'] ?? false)) {
            return 0;
        }
        $events = 0;
        $now = now('UTC');
        foreach (['response' => 'first_response_at', 'resolution' => 'resolved_at'] as $type => $completedColumn) {
            $due = $ticket->{$type.'_due_at'};
            if ($due === null) {
                continue;
            }
            $completed = $ticket->{$completedColumn};
            $breached = $due->lte($now) && ($completed === null || $completed->gt($due));
            $warning = $ticket->{$type.'_warning_at'};
            if ($breached && ! $ticket->{$type.'_breached'}) {
                $ticket->{$type.'_breached'} = true;
                $this->timeline->append($ticket, SupportActor::system(), 'support.sla.breached', null, [
                    'target' => $type, 'due_at' => $due->toIso8601String(), 'policy_version' => $ticket->policy_snapshot['version'],
                ]);
                $events++;
            } elseif (! $breached && $completed === null && $warning && $warning->lte($now) && $due->gt($now) && ! $ticket->{$type.'_warned'}) {
                $ticket->{$type.'_warned'} = true;
                $this->timeline->append($ticket, SupportActor::system(), 'support.sla.warning', null, [
                    'target' => $type, 'due_at' => $due->toIso8601String(), 'policy_version' => $ticket->policy_snapshot['version'],
                ]);
                $events++;
            }
        }

        return $events;
    }

    public function scan(?int $batch = null): array
    {
        $limit = max(1, min($batch ?? (int) config('support.sla.batch_size', 100), (int) config('support.sla.max_batch_size', 250)));
        $now = now('UTC');
        $ids = collect();
        foreach (['response' => 'first_response_at', 'resolution' => 'resolved_at'] as $type => $completed) {
            $base = SupportTicket::query()->whereIn('status', ['OPEN', 'IN_PROGRESS', 'WAITING', 'RESOLVED']);
            $due = (clone $base)->where($type.'_breached', false)->where($type.'_due_at', '<=', $now)
                ->where(fn ($query) => $query->whereNull($completed)->orWhereColumn($completed, '>', $type.'_due_at'))
                ->orderBy($type.'_due_at')->orderBy('id')->limit($limit)->pluck('id');
            $warning = (clone $base)->where($type.'_warned', false)->where($type.'_warning_at', '<=', $now)
                ->where($type.'_due_at', '>', $now)->whereNull($completed)
                ->orderBy($type.'_warning_at')->orderBy('id')->limit($limit)->pluck('id');
            $ids = $ids->merge($due)->merge($warning);
        }
        $result = ['processed' => 0, 'events' => 0];
        $started = hrtime(true);
        foreach ($ids->unique()->take($limit) as $id) {
            if ((hrtime(true) - $started) / 1_000_000_000 >= max(1, (int) config('support.sla.max_duration_seconds', 15))) {
                break;
            }
            $result['events'] += DB::transaction(function () use ($id): int {
                $ticket = SupportTicket::query()->whereKey($id)->lockForUpdate()->first();
                if (! $ticket) {
                    return 0;
                }
                $events = $this->evaluate($ticket);
                if ($events > 0) {
                    $ticket->save();
                }

                return $events;
            }, 3);
            $result['processed']++;
        }

        return $result;
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('SLA changes require the support aggregate transaction.');
        }
    }
}
