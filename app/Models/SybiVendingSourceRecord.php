<?php

namespace App\Models;

use App\Enums\Vending\SybiVendingSourceStatus;
use App\Enums\Vending\SybiVendingValidationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SybiVendingSourceRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid', 'sybi_id', 'identificador_vending', 'name', 'address_line',
        'neighborhood', 'postal_code', 'sybi_city_id', 'sybi_state_id',
        'sybi_full_address', 'latitude', 'longitude', 'source_status',
        'validation_status', 'validation_codes', 'first_seen_at', 'last_seen_at',
        'payload_hash', 'promoted_vending_machine_id',
    ];

    protected function casts(): array
    {
        return [
            'source_status' => SybiVendingSourceStatus::class,
            'validation_status' => SybiVendingValidationStatus::class,
            'validation_codes' => 'array',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'sybi_city_id' => 'integer',
            'sybi_state_id' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $record): void {
            $record->uuid ??= (string) Str::uuid();
        });
    }

    public function promotedVendingMachine(): BelongsTo
    {
        return $this->belongsTo(VendingMachine::class, 'promoted_vending_machine_id');
    }
}
