<?php

namespace App\Integrations\Sybi;

final readonly class SybiVendingRecordMapResult
{
    private function __construct(
        public ?SybiVendingSourceCandidate $candidate,
        public ?SybiVendingRejection $rejection,
    ) {}

    public static function accepted(SybiVendingSourceCandidate $candidate): self
    {
        return new self($candidate, null);
    }

    public static function rejected(SybiVendingRejection $rejection): self
    {
        return new self(null, $rejection);
    }

    public function isAccepted(): bool
    {
        return $this->candidate !== null;
    }
}
