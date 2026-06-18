<?php

namespace App\Models\Concerns;

use Carbon\Carbon;

trait ResolvesAttendanceLocalDateTime
{
    public function resolvedAttendanceLocalDateTime(): ?Carbon
    {
        $cached = $this->getAttribute('_resolved_local_check_at');

        if ($cached instanceof Carbon) {
            return $cached->copy();
        }

        $timezone = $this->attendanceOperationsTimezone();
        $rawPayload = is_array($this->raw_payload) ? $this->raw_payload : [];
        $rawLocal = $rawPayload['punched_at_local']
            ?? $rawPayload['event_time_local']
            ?? null;

        if ($resolved = $this->parseAttendanceDateTimeInTimezone($rawLocal, $timezone)) {
            $this->setAttribute('_resolved_local_check_at', $resolved);

            return $resolved->copy();
        }

        $rawUtc = $rawPayload['punched_at_utc']
            ?? $rawPayload['event_time_utc']
            ?? null;

        if ($resolved = $this->parseAttendanceUtcDateTimeForTimezone($rawUtc, $timezone)) {
            $this->setAttribute('_resolved_local_check_at', $resolved);

            return $resolved->copy();
        }

        $resolved = $this->convertAttendanceValueToTimezone($this->log_date, $timezone);
        $this->setAttribute('_resolved_local_check_at', $resolved);

        return $resolved?->copy();
    }

    public function resolvedAttendanceUtcDateTime(): ?Carbon
    {
        $cached = $this->getAttribute('_resolved_utc_check_at');

        if ($cached instanceof Carbon) {
            return $cached->copy();
        }

        $rawPayload = is_array($this->raw_payload) ? $this->raw_payload : [];
        $rawUtc = $rawPayload['punched_at_utc']
            ?? $rawPayload['event_time_utc']
            ?? null;

        if ($resolved = $this->parseAttendanceUtcDateTimeForTimezone($rawUtc, 'UTC')) {
            $this->setAttribute('_resolved_utc_check_at', $resolved);

            return $resolved->copy();
        }

        $resolved = $this->convertAttendanceValueToTimezone($this->log_date, 'UTC');
        $this->setAttribute('_resolved_utc_check_at', $resolved);

        return $resolved?->copy();
    }

    public function attendanceOperationsTimezone(): string
    {
        return (string) config('operations.timezone', 'America/Mexico_City');
    }

    protected function attendanceStorageTimezone(): string
    {
        return (string) config('operations.storage_timezone', 'UTC');
    }

    protected function parseAttendanceDateTimeInTimezone(mixed $value, string $timezone): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value, $timezone);
        } catch (\Throwable) {
            try {
                return Carbon::parse($value)->setTimezone($timezone);
            } catch (\Throwable) {
                return null;
            }
        }
    }

    protected function parseAttendanceUtcDateTimeForTimezone(mixed $value, string $timezone): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value, 'UTC')->setTimezone($timezone);
        } catch (\Throwable) {
            try {
                return Carbon::parse($value)->utc()->setTimezone($timezone);
            } catch (\Throwable) {
                return null;
            }
        }
    }

    protected function convertAttendanceValueToTimezone(mixed $value, string $timezone): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return Carbon::parse($value->format('Y-m-d H:i:s'), $this->attendanceStorageTimezone())
                ->setTimezone($timezone);
        }

        return Carbon::parse((string) $value, $this->attendanceStorageTimezone())
            ->setTimezone($timezone);
    }
}
