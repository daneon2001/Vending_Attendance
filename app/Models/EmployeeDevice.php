<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** FIELD_MOBILE only. One immutable ownership/key tuple per enrollment. */
class EmployeeDevice extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['public_key', 'key_fingerprint', 'verified_phone', 'request_hash'];

    protected function casts(): array
    {
        return ['verified_phone' => 'encrypted', 'phone_verified_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime', 'activated_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime', 'ended_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
