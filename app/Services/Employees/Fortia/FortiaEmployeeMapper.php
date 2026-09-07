<?php

namespace App\Services\Employees\Fortia;

use App\Services\Employees\EmployeeIdentityNormalizer;
use Carbon\CarbonImmutable;

class FortiaEmployeeMapper
{
    public function __construct(private readonly EmployeeIdentityNormalizer $normalizer) {}

    public function normalize(mixed $record): ?array
    {
        if (! is_array($record) || ! is_string($record['employee_number'] ?? null)
            || (! is_string($record['source_external_id'] ?? null) && ! is_int($record['source_external_id'] ?? null))) {
            return null;
        }
        $mapped = [
            'employee_number' => $this->normalizer->text($record['employee_number']),
            'full_name' => $this->normalizer->text($record['full_name'] ?? null),
            'status' => $this->normalizer->status($record['status'] ?? null),
            'source_external_id' => $this->normalizer->text($record['source_external_id']),
            'source_updated_at' => null,
        ];
        if ($this->normalizer->errors($mapped, 0) !== [] || $mapped['source_external_id'] === null
            || mb_strlen($mapped['source_external_id']) > 191 || preg_match('/[\x00-\x1F\x7F]/u', $mapped['source_external_id'])) {
            return null;
        }
        if (! empty($record['source_updated_at'])) {
            try {
                if (! is_string($record['source_updated_at']) || ! preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/', $record['source_updated_at'])) {
                    return null;
                }
                $mapped['source_updated_at'] = CarbonImmutable::parse($record['source_updated_at'])->utc()->toDateTimeString();
            } catch (\Throwable) {
                return null;
            }
        }

        return $mapped;
    }
}
