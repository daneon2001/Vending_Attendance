<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportIntegration extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    // Deliberately NOT HasApiTokens: legacy Sanctum routes must reject service tokens.
    public function tokens()
    {
        return $this->morphMany(\Laravel\Sanctum\PersonalAccessToken::class, 'tokenable');
    }

    public function machines()
    {
        return $this->belongsToMany(VendingMachine::class, 'support_integration_machines');
    }
}
