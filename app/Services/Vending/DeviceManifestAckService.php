<?php

namespace App\Services\Vending;

use App\Enums\Vending\ManifestAckStatus;
use App\Enums\Vending\ManifestType;
use App\Models\Device;
use App\Models\DeviceManifestState;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DeviceManifestAckService
{
    public function __construct(
        private readonly MachineConfigurationManifestService $configurationManifests,
        private readonly EmployeeManifestService $employeeManifests,
    ) {}

    public function acknowledge(Device $device, array $payload): array
    {
        return DB::transaction(function () use ($device, $payload): array {
            $lockedDevice = Device::query()->whereKey($device->getKey())->lockForUpdate()->firstOrFail();
            $type = ManifestType::from($payload['manifest_type']);
            $ackStatus = ManifestAckStatus::from($payload['status']);

            if (! $type->isSupported()) {
                return $this->error('UNSUPPORTED_MANIFEST_TYPE', 'Biometric manifests are not supported.', 422);
            }

            $state = DeviceManifestState::query()->firstOrCreate([
                'device_id' => $lockedDevice->id,
                'manifest_type' => $type->value,
            ]);
            $state = DeviceManifestState::query()->whereKey($state->id)->lockForUpdate()->firstOrFail();
            $snapshot = $this->currentSnapshot($lockedDevice, $type);
            $serverVersion = (int) $snapshot['manifest_version'];
            $serverHash = (string) $snapshot['manifest_hash'];
            $ackVersion = (int) $payload['manifest_version'];
            $ackHash = strtolower((string) $payload['manifest_hash']);
            $currentApplied = max(
                (int) ($state->applied_version ?? 0),
                (int) ($this->deviceAppliedVersion($lockedDevice, $type) ?? 0),
            );

            if ($ackVersion < $serverVersion || $ackVersion < $currentApplied) {
                AuditLogger::log('manifest.stale_ack', $lockedDevice, 'Stale manifest acknowledgement ignored.', [
                    'manifest_type' => $type->value,
                    'ack_version' => $ackVersion,
                    'server_version' => $serverVersion,
                    'applied_version' => $currentApplied ?: null,
                ]);

                return [
                    'ok' => true,
                    'stale' => true,
                    'duplicate' => false,
                    'manifest_type' => $type->value,
                    'manifest_version' => $ackVersion,
                    'server_version' => $serverVersion,
                    'applied_version' => $currentApplied ?: null,
                ];
            }

            if ($ackVersion > $serverVersion || ! hash_equals($serverHash, $ackHash)) {
                $state->forceFill([
                    'last_ack_status' => ManifestAckStatus::FAILED,
                    'last_ack_version' => $ackVersion,
                    'last_ack_hash' => $ackHash,
                    'last_ack_at' => now(),
                    'reported_applied_at' => isset($payload['applied_at']) ? Carbon::parse($payload['applied_at']) : null,
                    'last_error_code' => 'MANIFEST_HASH_MISMATCH',
                    'last_error_message' => null,
                ])->save();

                AuditLogger::log('manifest.hash_mismatch', $lockedDevice, 'Manifest acknowledgement hash or version mismatch.', [
                    'manifest_type' => $type->value,
                    'ack_version' => $ackVersion,
                    'server_version' => $serverVersion,
                ]);

                return array_merge($this->error('MANIFEST_HASH_MISMATCH', 'Manifest version or hash does not match current desired state.', 422), [
                    'manifest_type' => $type->value,
                    'server_version' => $serverVersion,
                    'server_hash' => $serverHash,
                    'applied_version' => $currentApplied ?: null,
                ]);
            }

            if ($this->isDuplicate($state, $ackStatus, $ackVersion, $ackHash, $payload)) {
                return [
                    'ok' => true,
                    'stale' => false,
                    'duplicate' => true,
                    'manifest_type' => $type->value,
                    'manifest_version' => $ackVersion,
                    'server_version' => $serverVersion,
                    'applied_version' => $state->applied_version,
                ];
            }

            $now = now();
            $common = [
                'last_ack_status' => $ackStatus,
                'last_ack_version' => $ackVersion,
                'last_ack_hash' => $ackHash,
                'last_ack_at' => $now,
                'reported_applied_at' => isset($payload['applied_at']) ? Carbon::parse($payload['applied_at']) : null,
            ];

            if ($ackStatus === ManifestAckStatus::FAILED) {
                $errorCode = $this->sanitize($payload['error_code'] ?? 'DEVICE_APPLY_FAILED', 100);
                $errorMessage = $this->sanitize($payload['error_message'] ?? null, 500);
                $state->forceFill(array_merge($common, [
                    'last_error_code' => $errorCode,
                    'last_error_message' => $errorMessage,
                ]))->save();

                AuditLogger::log('manifest.ack_failed', $lockedDevice, 'Device reported manifest application failure.', [
                    'manifest_type' => $type->value,
                    'manifest_version' => $ackVersion,
                    'error_code' => $errorCode,
                ]);
            } else {
                $state->forceFill(array_merge($common, [
                    'applied_version' => max($currentApplied, $ackVersion),
                    'applied_hash' => $ackHash,
                    'last_error_code' => null,
                    'last_error_message' => null,
                ]))->save();
                $this->mirrorAppliedVersion($lockedDevice, $type, max($currentApplied, $ackVersion));
            }

            return [
                'ok' => true,
                'stale' => false,
                'duplicate' => false,
                'manifest_type' => $type->value,
                'manifest_version' => $ackVersion,
                'server_version' => $serverVersion,
                'applied_version' => $ackStatus === ManifestAckStatus::APPLIED
                    ? max($currentApplied, $ackVersion)
                    : ($currentApplied ?: null),
                'ack_status' => $ackStatus->value,
            ];
        }, 3);
    }

    private function currentSnapshot(Device $device, ManifestType $type): array
    {
        return match ($type) {
            ManifestType::CONFIGURATION => $this->configurationManifests->snapshot($device),
            ManifestType::EMPLOYEES => $this->employeeManifests->snapshot($device->vendingMachine()->firstOrFail()),
            ManifestType::BIOMETRICS => [],
        };
    }

    private function deviceAppliedVersion(Device $device, ManifestType $type): ?int
    {
        return match ($type) {
            ManifestType::CONFIGURATION => $device->config_version_applied,
            ManifestType::EMPLOYEES => $device->employee_manifest_version_applied,
            ManifestType::BIOMETRICS => $device->biometric_manifest_version_applied,
        };
    }

    private function mirrorAppliedVersion(Device $device, ManifestType $type, int $version): void
    {
        $column = match ($type) {
            ManifestType::CONFIGURATION => 'config_version_applied',
            ManifestType::EMPLOYEES => 'employee_manifest_version_applied',
            ManifestType::BIOMETRICS => 'biometric_manifest_version_applied',
        };
        $device->forceFill([$column => max((int) ($device->{$column} ?? 0), $version)])->save();
    }

    private function isDuplicate(
        DeviceManifestState $state,
        ManifestAckStatus $status,
        int $version,
        string $hash,
        array $payload,
    ): bool {
        if ($state->last_ack_status !== $status
            || $state->last_ack_version !== $version
            || $state->last_ack_hash === null
            || ! hash_equals($state->last_ack_hash, $hash)) {
            return false;
        }

        if ($status === ManifestAckStatus::APPLIED) {
            return $state->applied_version === $version && $state->applied_hash === $hash;
        }

        return $state->last_error_code === $this->sanitize($payload['error_code'] ?? 'DEVICE_APPLY_FAILED', 100)
            && $state->last_error_message === $this->sanitize($payload['error_message'] ?? null, 500);
    }

    private function sanitize(?string $value, int $length): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return Str::limit(Str::squish(strip_tags($value)), $length, '');
    }

    private function error(string $code, string $message, int $status): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message, 'http_status' => $status];
    }
}
