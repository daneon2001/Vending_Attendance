<?php

namespace App\Services\Vending;

use App\Enums\Vending\CoordinateSource;
use App\Enums\Vending\VendingMachineStatus;
use App\Models\VendingMachine;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;

class VendingMachineImportService
{
    public function import(iterable $rows, bool $overwriteVerifiedCoordinates = false): array
    {
        $result = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'rejected' => 0, 'errors' => []];
        $seenMachineCodes = [];
        $seenSybiIds = [];

        foreach ($rows as $index => $row) {
            $row = is_array($row) ? $row : (array) $row;
            $machineCode = trim((string) ($row['machine_code'] ?? ''));
            $validator = Validator::make($row, [
                'machine_code' => ['required', 'string', 'max:100'],
                'sybi_id' => ['nullable', 'string', 'max:255'],
                'latitude' => ['required', 'numeric', 'between:-90,90'],
                'longitude' => ['required', 'numeric', 'between:-180,180'],
                'postal_code' => ['nullable', 'regex:/^\d{5}$/'],
            ]);

            $sybiId = filled($row['sybi_id'] ?? null) ? trim((string) $row['sybi_id']) : null;
            $duplicateMachineCode = isset($seenMachineCodes[$machineCode]);
            $duplicateSybiId = $sybiId !== null && isset($seenSybiIds[$sybiId]);
            $zeroCoordinates = (float) ($row['latitude'] ?? 0) === 0.0 && (float) ($row['longitude'] ?? 0) === 0.0;

            if ($validator->fails() || $duplicateMachineCode || $duplicateSybiId || $zeroCoordinates) {
                $result['rejected']++;
                $result['errors'][] = [
                    'row' => $index,
                    'machine_code' => $machineCode ?: null,
                    'reasons' => $validator->errors()->all() ?: [match (true) {
                        $duplicateMachineCode => 'DUPLICATE_MACHINE_CODE',
                        $duplicateSybiId => 'DUPLICATE_SYBI_ID',
                        default => 'ZERO_COORDINATES',
                    }],
                ];

                continue;
            }

            $seenMachineCodes[$machineCode] = true;
            if ($sybiId !== null) {
                $seenSybiIds[$sybiId] = true;
            }

            $machineBySybi = $sybiId !== null
                ? VendingMachine::query()->where('sybi_id', $sybiId)->first()
                : null;
            $machineByCode = VendingMachine::query()->where('machine_code', $machineCode)->first();

            if ($machineBySybi && $machineByCode && ! $machineBySybi->is($machineByCode)) {
                $result['rejected']++;
                $result['errors'][] = [
                    'row' => $index,
                    'machine_code' => $machineCode,
                    'reasons' => ['IDENTIFIER_CONFLICT'],
                ];

                continue;
            }

            $machine = $machineBySybi ?? $machineByCode;

            $attributes = Arr::only($row, [
                'operational_code', 'name', 'address_line', 'neighborhood', 'locality',
                'municipality', 'state', 'postal_code', 'country', 'latitude',
                'longitude', 'timezone', 'default_geofence_radius_m', 'metadata',
            ]);
            $attributes['machine_code'] = $machineCode;
            if ($sybiId !== null || ! $machine) {
                $attributes['sybi_id'] = $sybiId;
            }
            $attributes['coordinate_source'] = CoordinateSource::SYBI->value;

            if ($machine?->coordinates_verified && ! $overwriteVerifiedCoordinates) {
                unset($attributes['latitude'], $attributes['longitude'], $attributes['coordinate_source']);
            }

            try {
                if (! $machine) {
                    VendingMachine::query()->create(array_merge([
                        'status' => VendingMachineStatus::DRAFT->value,
                        'coordinates_verified' => false,
                    ], $attributes));
                    $result['created']++;
                } else {
                    $machine->fill($attributes);
                    if (! $machine->isDirty()) {
                        $result['unchanged']++;
                    } else {
                        $machine->save();
                        $result['updated']++;
                    }
                }
            } catch (QueryException) {
                $result['rejected']++;
                $result['errors'][] = [
                    'row' => $index,
                    'machine_code' => $machineCode,
                    'reasons' => ['PERSISTENCE_CONFLICT'],
                ];
            }
        }

        return $result;
    }
}
