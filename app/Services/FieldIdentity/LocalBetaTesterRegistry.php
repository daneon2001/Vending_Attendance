<?php

namespace App\Services\FieldIdentity;

use Carbon\CarbonImmutable;
use Throwable;

/** Private operator-owned registry. No runtime writer or real-data test fallback. */
class LocalBetaTesterRegistry
{
    protected function read(): array
    {
        if (! app()->environment('local') && ! \App\Support\InternalBeta::enabled()) {
            return [];
        }
        $path = (string) config('internal_beta.registry_path');
        if (! is_file($path) || is_link($path) || filesize($path) > 32768) {
            return [];
        }
        $resolved = realpath($path);
        $public = realpath(public_path());
        if ($resolved === false || ($public !== false && str_starts_with(str_replace('\\', '/', $resolved), str_replace('\\', '/', $public).'/'))
            || (PHP_OS_FAMILY !== 'Windows' && (fileperms($path) & 0007) !== 0)) {
            return [];
        }

        return json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
    }

    /** Never return partially valid configuration; never log parsing exceptions. */
    public function entries(): array
    {
        if (! \App\Support\InternalBeta::simulationAllowed() || config('internal_beta.testers_enabled') !== true) {
            return [];
        }
        try {
            $document = $this->read();
            if (($document['version'] ?? null) !== 1 || ! is_array($document['testers'] ?? null)
                || ! array_is_list($document['testers']) || count($document['testers']) > 10) {
                return [];
            }
            $users = $employees = $phones = [];
            foreach ($document['testers'] as $entry) {
                if (! is_array($entry) || ! is_int($entry['user_id'] ?? null) || $entry['user_id'] < 1
                    || ! is_int($entry['employee_id'] ?? null) || $entry['employee_id'] < 1
                    || ! is_bool($entry['enabled'] ?? null)
                    || ! is_string($entry['employee_number'] ?? null) || trim($entry['employee_number']) === ''
                    || ! in_array($entry['employee_source'] ?? null, \App\Support\InternalBeta::enabled() ? ['MANUAL', 'DEMO'] : ['MANUAL'], true)
                    || ! is_string($entry['phone_e164'] ?? null)
                    || ! preg_match('/^\+52[0-9]{10}$/D', $entry['phone_e164'])
                    || ! is_string($entry['approval_reference'] ?? null) || trim($entry['approval_reference']) === '') {
                    return [];
                }
                foreach (['created_at', 'updated_at', 'expires_at'] as $field) {
                    if (! is_string($entry[$field] ?? null)
                        || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $entry[$field])
                        || CarbonImmutable::parse($entry[$field])->format('Y-m-d\TH:i:s\Z') !== $entry[$field]) {
                        return [];
                    }
                }
                if ($entry['created_at'] > $entry['updated_at'] || $entry['updated_at'] >= $entry['expires_at']
                    || isset($users[$entry['user_id']]) || isset($employees[$entry['employee_id']])
                    || isset($phones[$entry['phone_e164']])) {
                    return [];
                }
                $users[$entry['user_id']] = $employees[$entry['employee_id']] = $phones[$entry['phone_e164']] = true;
            }

            return $document['testers'];
        } catch (Throwable) {
            return [];
        }
    }
}
