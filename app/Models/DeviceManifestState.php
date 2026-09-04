<?php

namespace App\Models;

use App\Enums\Vending\ManifestAckStatus;
use App\Enums\Vending\ManifestType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceManifestState extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id', 'manifest_type', 'applied_version', 'applied_hash',
        'last_ack_status', 'last_ack_version', 'last_ack_hash', 'last_ack_at',
        'reported_applied_at', 'last_error_code', 'last_error_message',
    ];

    protected function casts(): array
    {
        return [
            'manifest_type' => ManifestType::class,
            'last_ack_status' => ManifestAckStatus::class,
            'applied_version' => 'integer',
            'last_ack_version' => 'integer',
            'last_ack_at' => 'datetime',
            'reported_applied_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
