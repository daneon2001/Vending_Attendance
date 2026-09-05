<?php

namespace App\Services\Vending;

use App\Enums\Vending\MobileReleaseStatus;
use App\Models\MobileRelease;
use App\Models\MobileReleasePolicy;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MobileReleaseManagementService
{
    public function create(array $attributes, ?User $actor): MobileRelease
    {
        $targetType = $attributes['target_type'] ?? 'CHANNEL';
        $targetValue = $attributes['target_value'] ?? null;
        unset($attributes['target_type'], $attributes['target_value']);
        $attributes['created_by'] = $actor?->id;
        $attributes['artifact_sha256'] = isset($attributes['artifact_sha256'])
            ? strtolower((string) $attributes['artifact_sha256'])
            : null;
        if (($attributes['status'] ?? null) === MobileReleaseStatus::PUBLISHED->value) {
            $attributes['released_at'] ??= now();
        }

        return DB::transaction(function () use ($attributes, $targetType, $targetValue): MobileRelease {
            $release = MobileRelease::query()->create($attributes);
            if ($targetType !== 'CHANNEL' && filled($targetValue)) {
                $release->targets()->create(['target_type' => $targetType, 'target_value' => $targetValue]);
            }
            AuditLogger::log('mobile_release.created', $release, 'Mobile release metadata registered.', [
                'platform' => $release->platform->value,
                'channel' => $release->channel->value,
                'version' => $release->version,
                'build_number' => $release->build_number,
                'status' => $release->status->value,
                'target_type' => $targetType,
                'target_value' => $targetValue,
            ]);

            return $release;
        });
    }

    public function addTarget(MobileRelease $release, array $attributes): void
    {
        $release->targets()->firstOrCreate($attributes);
        AuditLogger::log('mobile_release.target_added', $release, 'Mobile release rollout target added.', $attributes);
    }

    public function updatePolicy(array $attributes, ?User $actor): MobileReleasePolicy
    {
        $ids = array_filter(Arr::only($attributes, [
            'current_release_id', 'recommended_release_id', 'minimum_release_id',
        ]));
        $releases = MobileRelease::query()->whereIn('id', $ids)->get()->keyBy('id');

        foreach ($ids as $field => $id) {
            $release = $releases->get($id);
            if (! $release
                || $release->platform->value !== $attributes['platform']
                || $release->channel->value !== $attributes['channel']
                || $release->status !== MobileReleaseStatus::PUBLISHED) {
                throw ValidationException::withMessages([
                    $field => 'La release debe estar publicada y pertenecer a la misma plataforma/canal.',
                ]);
            }
        }

        $current = isset($attributes['current_release_id']) ? $releases->get($attributes['current_release_id']) : null;
        $recommended = isset($attributes['recommended_release_id']) ? $releases->get($attributes['recommended_release_id']) : null;
        $minimum = isset($attributes['minimum_release_id']) ? $releases->get($attributes['minimum_release_id']) : null;
        if ($minimum && $current && $this->compare($minimum, $current) > 0) {
            throw ValidationException::withMessages(['minimum_release_id' => 'Minimum no puede ser posterior a current.']);
        }
        if ($current && $recommended && $this->compare($current, $recommended) > 0) {
            throw ValidationException::withMessages(['recommended_release_id' => 'Recommended no puede ser anterior a current.']);
        }

        return DB::transaction(function () use ($attributes, $actor): MobileReleasePolicy {
            $policy = MobileReleasePolicy::query()->updateOrCreate(
                Arr::only($attributes, ['platform', 'channel']),
                array_merge(Arr::only($attributes, [
                    'current_release_id', 'recommended_release_id', 'minimum_release_id',
                ]), ['updated_by' => $actor?->id]),
            );
            AuditLogger::log('mobile_release.policy_updated', $policy, 'Mobile release policy updated.', [
                'platform' => $attributes['platform'],
                'channel' => $attributes['channel'],
                'current_release_id' => $attributes['current_release_id'] ?? null,
                'recommended_release_id' => $attributes['recommended_release_id'] ?? null,
                'minimum_release_id' => $attributes['minimum_release_id'] ?? null,
            ]);

            return $policy;
        });
    }

    private function compare(MobileRelease $left, MobileRelease $right): int
    {
        $comparison = version_compare(ltrim($left->version, 'vV'), ltrim($right->version, 'vV'));

        return $comparison !== 0
            ? $comparison
            : ((int) $left->build_number <=> (int) $right->build_number);
    }
}
