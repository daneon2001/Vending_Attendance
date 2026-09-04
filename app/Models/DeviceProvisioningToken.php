<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class DeviceProvisioningToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid', 'vending_machine_id', 'expires_at', 'used_at',
        'used_by_device_id', 'revoked_at', 'created_by', 'revoked_by',
        'revocation_reason',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $token): void {
            $token->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function vendingMachine(): BelongsTo
    {
        return $this->belongsTo(VendingMachine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function usedByDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'used_by_device_id');
    }

    public function isUsable(): bool
    {
        return $this->used_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }
}
