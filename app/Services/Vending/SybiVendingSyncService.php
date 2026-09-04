<?php

namespace App\Services\Vending;

use App\Enums\Vending\SybiVendingErrorCode;
use App\Enums\Vending\SybiVendingRunStatus;
use App\Enums\Vending\SybiVendingSourceStatus;
use App\Enums\Vending\SybiVendingSyncStatus;
use App\Enums\Vending\SybiVendingValidationCode;
use App\Enums\Vending\SybiVendingValidationStatus;
use App\Integrations\Sybi\SybiVendingApiClient;
use App\Integrations\Sybi\SybiVendingApiException;
use App\Integrations\Sybi\SybiVendingApiResponse;
use App\Integrations\Sybi\SybiVendingRecordMapper;
use App\Integrations\Sybi\SybiVendingRejection;
use App\Integrations\Sybi\SybiVendingSourceCandidate;
use App\Models\SybiVendingSourceRecord;
use App\Models\SybiVendingSyncRun;
use App\Models\VendingMachine;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Throwable;

class SybiVendingSyncService
{
    public function __construct(
        private readonly SybiVendingApiClient $client,
        private readonly SybiVendingRecordMapper $mapper,
        private readonly SybiVendingPromotionService $promotion,
    ) {}

    public function sync(bool $dryRun = false, ?int $initiatedBy = null): array
    {
        $startedAt = now()->utc();
        $startedNs = hrtime(true);

        if ($dryRun) {
            return $this->process($this->client->fetchMachines(), true, $startedNs);
        }

        $run = SybiVendingSyncRun::query()->create([
            'started_at' => $startedAt,
            'status' => SybiVendingRunStatus::RUNNING,
            'initiated_by' => $initiatedBy,
        ]);
        AuditLogger::log('sybi.vending.sync.started', $run, 'SYBIML vending synchronization started.');

        try {
            $response = $this->client->fetchMachines();
            $summary = DB::transaction(fn (): array => $this->process($response, false, $startedNs), 3);
            $status = $summary['warnings'] === []
                ? SybiVendingRunStatus::COMPLETED
                : SybiVendingRunStatus::COMPLETED_WITH_WARNINGS;

            $run->forceFill(array_merge($this->runCounters($summary), [
                'finished_at' => now()->utc(),
                'status' => $status,
                'duration_ms' => $summary['duration_ms'],
                'http_status' => $summary['http_status'],
                'warnings' => $summary['warnings'] ?: null,
            ]))->save();
            AuditLogger::log('sybi.vending.sync.completed', $run, 'SYBIML vending synchronization completed.', [
                'after' => $this->runCounters($summary),
                'warnings' => $summary['warnings'],
            ]);

            return array_merge($summary, ['run_uuid' => $run->uuid, 'status' => $status->value]);
        } catch (SybiVendingApiException $exception) {
            $this->failRun($run, $exception->errorCode, $exception->httpStatus);
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->failRun($run, SybiVendingErrorCode::SYNC_ERROR);

            throw new SybiVendingApiException(
                SybiVendingErrorCode::SYNC_ERROR,
                'The SYBIML vending synchronization could not be completed.',
            );
        }
    }

    private function process(SybiVendingApiResponse $response, bool $dryRun, int $startedNs): array
    {
        $summary = $this->emptySummary($response, $dryRun);
        /** @var array<int, SybiVendingSourceCandidate> $candidates */
        $candidates = [];

        foreach ($response->data as $index => $record) {
            $result = $this->mapper->map($record, (int) $index);
            if (! $result->isAccepted()) {
                /** @var SybiVendingRejection $rejection */
                $rejection = $result->rejection;
                $summary['source_invalid']++;
                $summary['rejections'][] = $rejection;
                $this->incrementReason($summary['rejection_reasons'], $rejection->errorCode);

                continue;
            }

            /** @var SybiVendingSourceCandidate $candidate */
            $candidate = $result->candidate;
            $candidates[] = $candidate;
        }

        $summary['source_candidates'] = count($candidates);
        $this->classifyIdentityConflicts($candidates);
        foreach ($candidates as $candidate) {
            $this->appendCandidateDiagnostics($candidate, $summary);
        }
        $seenSybiIds = [];

        foreach ($candidates as $candidate) {
            $this->countValidation($candidate, $summary);
            if (isset($seenSybiIds[$candidate->sybiId])) {
                $summary['source_invalid']++;
                $this->incrementReason($summary['rejection_reasons'], SybiVendingValidationCode::DUPLICATE_SYBI_ID->value);

                continue;
            }
            $seenSybiIds[$candidate->sybiId] = true;

            $sourceDecision = $this->sourceDecision($candidate);
            $summary['source_'.strtolower($sourceDecision)]++;

            $sourceRecord = null;
            if (! $dryRun) {
                $sourceRecord = $this->persistSource($candidate, $sourceDecision);
            }

            if (! $candidate->isReady()) {
                continue;
            }

            $promotion = $this->promotion->promote($candidate, $dryRun, $sourceRecord);
            if (in_array($promotion->action, ['CREATED', 'UPDATED', 'UNCHANGED'], true)) {
                $summary['operational_'.strtolower($promotion->action)]++;
            } elseif ($promotion->action === 'CONFLICT') {
                $summary['operational_conflicts']++;
                $this->incrementReason($summary['conflict_reasons'], $promotion->reason ?? 'IDENTITY_COLLISION');
            }
        }

        $this->reconcileMissing(array_keys($seenSybiIds), $summary, $dryRun);
        $this->applyCompatibilityCounters($summary);
        $summary['duration_ms'] = (int) round((hrtime(true) - $startedNs) / 1_000_000);

        return $summary;
    }

    /** @param array<int, SybiVendingSourceCandidate> $candidates */
    private function classifyIdentityConflicts(array $candidates): void
    {
        $sybiCounts = collect($candidates)->countBy(fn (SybiVendingSourceCandidate $candidate): string => $candidate->sybiId);
        $identifierCounts = collect($candidates)
            ->filter(fn (SybiVendingSourceCandidate $candidate): bool => $candidate->vendingIdentifier !== null)
            ->countBy(fn (SybiVendingSourceCandidate $candidate): string => mb_strtolower((string) $candidate->vendingIdentifier));

        foreach ($candidates as $candidate) {
            if ($sybiCounts->get($candidate->sybiId, 0) > 1) {
                $candidate->addValidationCode(SybiVendingValidationCode::DUPLICATE_SYBI_ID);
            }
            if ($candidate->vendingIdentifier !== null
                && $identifierCounts->get(mb_strtolower($candidate->vendingIdentifier), 0) > 1) {
                $candidate->addValidationCode(SybiVendingValidationCode::DUPLICATE_VENDING_IDENTIFIER);
            }
            if (! $candidate->hasCode(SybiVendingValidationCode::DUPLICATE_VENDING_IDENTIFIER)
                && $this->promotion->hasIdentityCollision($candidate)) {
                $candidate->addValidationCode(SybiVendingValidationCode::DUPLICATE_VENDING_IDENTIFIER);
            }
        }
    }

    private function countValidation(SybiVendingSourceCandidate $candidate, array &$summary): void
    {
        if ($candidate->hasLocationIssue()) {
            $summary['operational_incomplete']++;
        }
        if ($candidate->hasCode(SybiVendingValidationCode::DUPLICATE_VENDING_IDENTIFIER)) {
            $summary['operational_conflicts']++;
            $this->incrementReason($summary['conflict_reasons'], SybiVendingValidationCode::DUPLICATE_VENDING_IDENTIFIER->value);
        }

        if ($candidate->validationStatus() === SybiVendingValidationStatus::READY) {
            $summary['operational_ready']++;
        } elseif ($candidate->validationStatus() === SybiVendingValidationStatus::INVALID) {
            $summary['operational_invalid']++;
        }
    }

    private function appendCandidateDiagnostics(SybiVendingSourceCandidate $candidate, array &$summary): void
    {
        $details = [
            SybiVendingValidationCode::ZERO_COORDINATES->value => ['latitud,longitud', 'Coordinate pair 0,0 is retained as incomplete source evidence.'],
            SybiVendingValidationCode::MISSING_COORDINATES->value => ['latitud,longitud', 'One or both source coordinates are missing.'],
            SybiVendingValidationCode::INVALID_COORDINATES->value => ['latitud,longitud', 'One or both source coordinates are outside the accepted numeric ranges.'],
            SybiVendingValidationCode::MISSING_VENDING_IDENTIFIER->value => ['identificador_vending', 'The source vending identifier is missing.'],
            SybiVendingValidationCode::INVALID_VENDING_IDENTIFIER->value => ['identificador_vending', 'The source vending identifier has an invalid type or length.'],
            SybiVendingValidationCode::DUPLICATE_VENDING_IDENTIFIER->value => ['identificador_vending', 'The source vending identifier is not unambiguous.'],
            SybiVendingValidationCode::DUPLICATE_SYBI_ID->value => ['id_sucursal', 'Duplicate id_sucursal cannot represent two distinct source records.'],
            SybiVendingValidationCode::INVALID_LOCATION_STRUCTURE->value => ['ubicacion', 'The source location structure is invalid.'],
            SybiVendingValidationCode::INVALID_SOURCE_FIELD->value => [null, 'A source field has an invalid type or length.'],
        ];

        foreach ($candidate->validationCodeValues() as $code) {
            if (! isset($details[$code])) {
                continue;
            }
            [$field, $reason] = $details[$code];
            $summary['rejections'][] = new SybiVendingRejection(
                $candidate->index,
                $candidate->sybiId,
                $candidate->vendingIdentifier,
                $field,
                $code,
                $reason,
            );
        }
    }

    private function sourceDecision(SybiVendingSourceCandidate $candidate): string
    {
        $existing = SybiVendingSourceRecord::query()->where('sybi_id', $candidate->sybiId)->first();
        if (! $existing) {
            return 'CREATED';
        }

        $attributes = $candidate->sourceAttributes();
        $codesChanged = $this->normalizedCodes($existing->validation_codes ?? [])
            !== $this->normalizedCodes($attributes['validation_codes']);
        $changed = $existing->payload_hash !== $attributes['payload_hash']
            || $existing->source_status !== SybiVendingSourceStatus::PRESENT
            || $existing->validation_status !== $candidate->validationStatus()
            || $codesChanged;

        return $changed ? 'UPDATED' : 'UNCHANGED';
    }

    private function persistSource(
        SybiVendingSourceCandidate $candidate,
        string $decision,
    ): SybiVendingSourceRecord {
        $record = SybiVendingSourceRecord::query()->firstOrNew(['sybi_id' => $candidate->sybiId]);
        if (! $record->exists) {
            $record->first_seen_at = now()->utc();
        }

        $record->fill($candidate->sourceAttributes());
        $record->last_seen_at = now()->utc();
        if ($record->promoted_vending_machine_id === null) {
            $record->promoted_vending_machine_id = VendingMachine::query()
                ->where('sybi_id', $candidate->sybiId)
                ->value('id');
        }
        $record->save();

        if ($decision !== 'UNCHANGED') {
            AuditLogger::log(
                'sybi.vending.source_record.'.strtolower($decision),
                $record,
                'SYBIML source projection record persisted.',
                ['after' => [
                    'sybi_id' => $record->sybi_id,
                    'validation_status' => $record->validation_status->value,
                    'validation_codes' => $record->validation_codes,
                ]],
            );
        }

        return $record;
    }

    private function reconcileMissing(array $seenSybiIds, array &$summary, bool $dryRun): void
    {
        $known = SybiVendingSourceRecord::query();
        $knownCount = (clone $known)->count();

        if ($seenSybiIds === [] && $knownCount > 0) {
            $summary['empty_source_guarded'] = true;
            $summary['warnings'][] = 'SOURCE_EMPTY_UNEXPECTED';

            return;
        }

        $missing = (clone $known)
            ->when($seenSybiIds !== [], fn ($query) => $query->whereNotIn('sybi_id', $seenSybiIds))
            ->get();
        $summary['operational_missing'] = $missing->count();

        if ($dryRun) {
            return;
        }

        foreach ($missing as $record) {
            if ($record->source_status !== SybiVendingSourceStatus::SOURCE_MISSING) {
                $record->forceFill([
                    'source_status' => SybiVendingSourceStatus::SOURCE_MISSING,
                    'validation_status' => SybiVendingValidationStatus::SOURCE_MISSING,
                ])->save();
                AuditLogger::log('sybi.vending.source_record.missing', $record, 'SYBIML source record was absent from the latest non-empty response.', [
                    'after' => ['sybi_id' => $record->sybi_id, 'source_status' => SybiVendingSourceStatus::SOURCE_MISSING->value],
                ]);
            }

            $machine = $record->promotedVendingMachine
                ?? VendingMachine::query()->where('sybi_id', (string) $record->sybi_id)->first();
            if ($machine && $machine->sybi_sync_status !== SybiVendingSyncStatus::SOURCE_MISSING) {
                $machine->forceFill(['sybi_sync_status' => SybiVendingSyncStatus::SOURCE_MISSING])->save();
                AuditLogger::log('vending_machine.sybi_missing', $machine, 'Previously promoted machine was not present in the latest non-empty SYBIML response.');
            }
        }
    }

    private function emptySummary(SybiVendingApiResponse $response, bool $dryRun): array
    {
        return [
            'dry_run' => $dryRun,
            'http_status' => $response->httpStatus,
            'declared_total' => $response->total,
            'received_total' => count($response->data),
            'source_candidates' => 0,
            'source_created' => 0,
            'source_updated' => 0,
            'source_unchanged' => 0,
            'source_invalid' => 0,
            'operational_ready' => 0,
            'operational_created' => 0,
            'operational_updated' => 0,
            'operational_unchanged' => 0,
            'operational_incomplete' => 0,
            'operational_conflicts' => 0,
            'operational_invalid' => 0,
            'operational_missing' => 0,
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'rejected' => 0,
            'conflicts' => 0,
            'missing' => 0,
            'empty_source_guarded' => false,
            'warnings' => $response->warnings,
            'rejection_reasons' => [],
            'rejections' => [],
            'conflict_reasons' => [],
        ];
    }

    private function applyCompatibilityCounters(array &$summary): void
    {
        $summary['created'] = $summary['operational_created'];
        $summary['updated'] = $summary['operational_updated'];
        $summary['unchanged'] = $summary['operational_unchanged'];
        $summary['rejected'] = $summary['source_invalid'];
        $summary['conflicts'] = $summary['operational_conflicts'];
        $summary['missing'] = $summary['operational_missing'];
    }

    /** @return list<string> */
    private function normalizedCodes(array $codes): array
    {
        $codes = array_values(array_map('strval', $codes));
        sort($codes, SORT_STRING);

        return $codes;
    }

    private function failRun(SybiVendingSyncRun $run, SybiVendingErrorCode $code, ?int $httpStatus = null): void
    {
        $run->forceFill([
            'finished_at' => now()->utc(),
            'status' => SybiVendingRunStatus::FAILED,
            'http_status' => $httpStatus,
            'error_code' => $code,
            'error_message' => 'SYBIML vending synchronization failed. Review the sanitized error code.',
        ])->save();
        AuditLogger::log('sybi.vending.sync.failed', $run, 'SYBIML vending synchronization failed.', [
            'error_code' => $code->value,
            'http_status' => $httpStatus,
        ]);
    }

    private function runCounters(array $summary): array
    {
        return [
            'received' => $summary['received_total'],
            'source_candidates' => $summary['source_candidates'],
            'source_created' => $summary['source_created'],
            'source_updated' => $summary['source_updated'],
            'source_unchanged' => $summary['source_unchanged'],
            'source_invalid' => $summary['source_invalid'],
            'operational_ready' => $summary['operational_ready'],
            'operational_created' => $summary['operational_created'],
            'operational_updated' => $summary['operational_updated'],
            'operational_unchanged' => $summary['operational_unchanged'],
            'operational_incomplete' => $summary['operational_incomplete'],
            'operational_conflicts' => $summary['operational_conflicts'],
            'operational_invalid' => $summary['operational_invalid'],
            'operational_missing' => $summary['operational_missing'],
            'created' => $summary['created'],
            'updated' => $summary['updated'],
            'unchanged' => $summary['unchanged'],
            'rejected' => $summary['rejected'],
            'conflicts' => $summary['conflicts'],
            'missing' => $summary['missing'],
        ];
    }

    private function incrementReason(array &$reasons, string $reason): void
    {
        $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
    }
}
