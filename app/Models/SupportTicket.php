<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportTicket extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'reported_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'location' => 'array',
            'geofence_context' => 'array',
            'policy_snapshot' => 'array',
            'response_due_at' => 'immutable_datetime',
            'resolution_due_at' => 'immutable_datetime',
            'response_warning_at' => 'immutable_datetime',
            'resolution_warning_at' => 'immutable_datetime',
            'first_response_at' => 'immutable_datetime',
            'response_breached' => 'boolean',
            'resolution_breached' => 'boolean',
            'response_warned' => 'boolean',
            'resolution_warned' => 'boolean',
        ];
    }

    public function vendingMachine()
    {
        return $this->belongsTo(VendingMachine::class);
    }

    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function reporterUser()
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    public function integration()
    {
        return $this->belongsTo(SupportIntegration::class, 'integration_id');
    }

    public function events()
    {
        return $this->hasMany(SupportTicketEvent::class, 'ticket_id');
    }

    public function evidence()
    {
        return $this->hasMany(SupportEvidence::class, 'ticket_id');
    }

    public function getFolioAttribute(): string
    {
        return 'INC-'.$this->created_at->format('Y').'-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function terminal(): bool
    {
        return in_array($this->status, ['CLOSED', 'CANCELLED'], true);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
