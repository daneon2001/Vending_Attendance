<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class AuditLogger
{
    public static function log(string $event, ?Model $auditable = null, ?string $description = null, array $metadata = []): void
    {
        $user = auth()->user();
        $request = request();

        $cleanMetadata = Arr::wrap($metadata);
        if ($cleanMetadata === [[]]) {
            $cleanMetadata = [];
        }

        AuditLog::create([
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'user_email' => $user?->email,
            'event' => $event,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'description' => $description,
            'metadata' => empty($cleanMetadata) ? null : $cleanMetadata,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }
}
