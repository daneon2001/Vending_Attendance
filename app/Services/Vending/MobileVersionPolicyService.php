<?php

namespace App\Services\Vending;

use App\Enums\Vending\MobileReleaseChannel;
use App\Enums\Vending\MobileVersionStatus;
use App\Models\Device;
use App\Models\MobileRelease;
use App\Models\MobileReleasePolicy;
use Illuminate\Support\Collection;

class MobileVersionPolicyService
{
    /** @return Collection<string, MobileReleasePolicy> */
    public function policyMap(): Collection
    {
        return MobileReleasePolicy::query()
            ->with(['currentRelease.targets', 'recommendedRelease.targets', 'minimumRelease.targets'])
            ->get()
            ->keyBy(fn (MobileReleasePolicy $policy): string => $this->key(
                $policy->platform->value,
                $policy->channel->value,
            ));
    }

    public function policyFor(Device $device, ?Collection $policies = null): ?MobileReleasePolicy
    {
        $platform = strtoupper(trim((string) $device->platform));
        $channel = strtoupper(trim((string) ($device->release_channel ?: MobileReleaseChannel::PRODUCTION->value)));

        return ($policies ?? $this->policyMap())->get($this->key($platform, $channel));
    }

    /** @return array<string, mixed> */
    public function evaluate(Device $device, ?MobileReleasePolicy $policy = null): array
    {
        $policy ??= $this->policyFor($device);

        return $this->evaluateWithPolicy($device, $policy);
    }

    /** @return array<string, mixed> */
    public function evaluateWithPolicy(Device $device, ?MobileReleasePolicy $policy): array
    {
        if ($policy === null || blank($device->app_version)) {
            return $this->result(MobileVersionStatus::UNKNOWN, $policy);
        }

        $minimum = $policy->minimumRelease;
        $current = $policy->currentRelease;
        $recommended = $policy->recommendedRelease;

        if ($minimum && $this->isOlderThan($device, $minimum)) {
            return $this->result(MobileVersionStatus::UNSUPPORTED, $policy);
        }

        $target = $recommended && $this->eligibleForRollout($device, $recommended)
            ? $recommended
            : $current;

        if ($target && $this->minimumOsUnsupported($device, $target)) {
            return $this->result(MobileVersionStatus::UNSUPPORTED, $policy);
        }

        if ($target && $this->isOlderThan($device, $target)) {
            return $this->result(
                $target->mandatory ? MobileVersionStatus::UPDATE_REQUIRED : MobileVersionStatus::UPDATE_AVAILABLE,
                $policy,
            );
        }

        return $this->result(MobileVersionStatus::CURRENT, $policy);
    }

    public function eligibleForRollout(Device $device, MobileRelease $release): bool
    {
        $targets = $release->relationLoaded('targets') ? $release->targets : $release->targets()->get();
        $targetMatches = $targets->isEmpty() || $targets->contains(function ($target) use ($device): bool {
            return match ($target->target_type->value) {
                'DEVICE' => hash_equals((string) $target->target_value, (string) $device->uuid),
                'GROUP' => filled($device->release_group)
                    && hash_equals((string) $target->target_value, (string) $device->release_group),
                default => false,
            };
        });
        if (! $targetMatches) {
            return false;
        }

        $percentage = (int) $release->rollout_percentage;
        if ($percentage <= 0) {
            return false;
        }
        if ($percentage >= 100) {
            return true;
        }

        $bucket = (int) hexdec(substr(hash('sha256', (string) $device->uuid), 0, 8)) % 100;

        return $bucket < $percentage;
    }

    private function isOlderThan(Device $device, MobileRelease $release): bool
    {
        $versionComparison = version_compare(
            ltrim((string) $device->app_version, 'vV'),
            ltrim((string) $release->version, 'vV'),
        );

        if ($versionComparison !== 0) {
            return $versionComparison < 0;
        }

        return $device->app_build_number !== null
            && (int) $device->app_build_number < (int) $release->build_number;
    }

    private function minimumOsUnsupported(Device $device, MobileRelease $release): bool
    {
        return filled($release->minimum_os)
            && filled($device->platform_version)
            && version_compare(
                ltrim((string) $device->platform_version, 'vV'),
                ltrim((string) $release->minimum_os, 'vV'),
                '<',
            );
    }

    /** @return array<string, mixed> */
    private function result(MobileVersionStatus $status, ?MobileReleasePolicy $policy): array
    {
        $serialize = static fn (?MobileRelease $release): ?array => $release ? [
            'uuid' => $release->uuid,
            'version' => $release->version,
            'build_number' => (int) $release->build_number,
            'mandatory' => (bool) $release->mandatory,
            'rollout_percentage' => (int) $release->rollout_percentage,
        ] : null;

        return [
            'status' => $status->value,
            'current' => $serialize($policy?->currentRelease),
            'recommended' => $serialize($policy?->recommendedRelease),
            'minimum' => $serialize($policy?->minimumRelease),
        ];
    }

    private function key(string $platform, string $channel): string
    {
        return strtoupper($platform).':'.strtoupper($channel);
    }
}
