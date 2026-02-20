<?php

namespace App\Services\Attendance;

use App\Models\AttendanceRecord;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Schema;

class AttendanceIntegrityService
{
    public const HASH_VERSION = 1;

    /**
     * Alias requested in technical/legal requirements.
     *
     * @param  array{from?:string|null,to?:string|null}  $periodo
     * @return array<string, mixed>
     */
    public function verify_integrity(array $periodo = []): array
    {
        $from = ! empty($periodo['from']) ? Carbon::parse((string) $periodo['from'])->startOfDay() : null;
        $to = ! empty($periodo['to']) ? Carbon::parse((string) $periodo['to'])->endOfDay() : null;

        return $this->verifyIntegrity($from, $to);
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyIntegrity(?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        if (! $this->supportsIntegrityColumns()) {
            return [
                'ok' => false,
                'reason' => 'INTEGRITY_COLUMNS_MISSING',
                'total_checked' => 0,
                'compromised_count' => 0,
                'compromised' => [],
            ];
        }

        $query = AttendanceRecord::query()
            ->orderBy('id')
            ->when($from, fn ($builder) => $builder->where('log_date', '>=', $from))
            ->when($to, fn ($builder) => $builder->where('log_date', '<=', $to));

        /** @var Collection<int, AttendanceRecord> $records */
        $records = $query->get();

        $compromised = [];
        $verifiedIds = [];

        foreach ($records as $record) {
            $issues = $this->detectRecordIssues($record);
            if ($issues !== []) {
                $compromised[] = [
                    'attendance_id' => (int) $record->id,
                    'employee_id' => $record->employee_id ? (int) $record->employee_id : null,
                    'log_date' => optional($record->log_date)?->toISOString(),
                    'issues' => $issues,
                ];

                continue;
            }

            $verifiedIds[] = (int) $record->id;
        }

        if ($verifiedIds !== [] && Schema::hasColumn('attendance_logs', 'integrity_verified_at')) {
            AttendanceRecord::query()
                ->whereIn('id', $verifiedIds)
                ->update(['integrity_verified_at' => now('UTC')]);
        }

        return [
            'ok' => count($compromised) === 0,
            'reason' => count($compromised) === 0 ? null : 'INTEGRITY_VIOLATION_DETECTED',
            'total_checked' => $records->count(),
            'compromised_count' => count($compromised),
            'compromised' => $compromised,
        ];
    }

    public function sealRecord(AttendanceRecord $record): void
    {
        if (! $this->supportsIntegrityColumns()) {
            return;
        }

        $previousHash = $this->resolvePreviousHash((int) $record->id);
        $hash = $this->computeHash($record, $previousHash);

        AttendanceRecord::query()
            ->whereKey($record->id)
            ->update([
                'integrity_previous_hash' => $previousHash,
                'integrity_hash' => $hash,
                'integrity_hash_version' => self::HASH_VERSION,
                'integrity_verified_at' => null,
            ]);
    }

    /**
     * @return array<int, string>
     */
    public function detectRecordIssues(AttendanceRecord $record): array
    {
        $issues = [];
        $storedHash = trim((string) $record->integrity_hash);
        $storedPrevHash = trim((string) $record->integrity_previous_hash);

        if ($storedHash === '') {
            $issues[] = 'MISSING_HASH';

            return $issues;
        }

        $expectedPrevHash = trim((string) $this->resolvePreviousHash((int) $record->id));
        if (! hash_equals($expectedPrevHash, $storedPrevHash)) {
            $issues[] = 'PREVIOUS_HASH_MISMATCH';
        }

        $expectedHash = $this->computeHash($record, $record->integrity_previous_hash);
        if (! hash_equals($expectedHash, $storedHash)) {
            $issues[] = 'ROW_HASH_MISMATCH';
        }

        return $issues;
    }

    public function computeHash(AttendanceRecord $record, ?string $previousHash): string
    {
        $payloadOriginal = $this->stableJson($record->raw_payload);

        $timestamp = $record->log_date
            ? $record->log_date->copy()->utc()->format('Y-m-d\TH:i:s.u\Z')
            : '';

        $canonical = implode('|', [
            (string) $record->id,
            (string) $record->employee_id,
            $timestamp,
            (string) ($record->device_id ?? ''),
            (string) ($record->source ?? ''),
            $payloadOriginal,
            (string) ($previousHash ?? ''),
        ]);

        return hash('sha256', $canonical);
    }

    private function resolvePreviousHash(int $recordId): ?string
    {
        if ($recordId <= 0) {
            return null;
        }

        return AttendanceRecord::query()
            ->where('id', '<', $recordId)
            ->orderByDesc('id')
            ->value('integrity_hash');
    }

    private function supportsIntegrityColumns(): bool
    {
        return Schema::hasTable('attendance_logs')
            && Schema::hasColumn('attendance_logs', 'integrity_hash')
            && Schema::hasColumn('attendance_logs', 'integrity_previous_hash');
    }

    /**
     * @param  mixed  $value
     */
    private function stableJson($value): string
    {
        $normalized = $this->normalizeRecursive($value);

        return json_encode(
            $normalized,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        ) ?: 'null';
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    private function normalizeRecursive($value)
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->normalizeRecursive($item), $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeRecursive($item);
        }

        return $value;
    }
}
