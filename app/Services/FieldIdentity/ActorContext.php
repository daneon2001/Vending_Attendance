<?php

namespace App\Services\FieldIdentity;

/**
 * Verified identity snapshot, NOT an authorization grant. Consumers must enforce
 * RBAC + machine assignment/scope and persist context atomically with an action.
 */
final readonly class ActorContext
{
    public function __construct(
        public int $userId,
        public int $employeeId,
        public int $deviceId,
        public int $deviceAssignmentId,
        public bool $phoneVerified,
        public bool $deviceVerified,
        public string $capturedAt,
        public string $proofUuid,
        public string $phoneVerificationMethod,
    ) {}
}
