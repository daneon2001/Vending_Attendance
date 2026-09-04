<?php

namespace App\Integrations\Sybi;

use App\Enums\Vending\SybiVendingValidationCode;

class SybiVendingRecordMapper
{
    public function map(mixed $record, int $index = 0): SybiVendingRecordMapResult
    {
        if (! is_array($record)) {
            return $this->invalid($index, $record, null, 'INVALID_RECORD', 'Expected a record object.');
        }

        $sybiId = $this->positiveInteger($record['id_sucursal'] ?? null);
        if ($sybiId === null) {
            $missing = ! array_key_exists('id_sucursal', $record)
                || $record['id_sucursal'] === null
                || $record['id_sucursal'] === '';

            return $this->invalid(
                $index,
                $record,
                'id_sucursal',
                $missing ? 'MISSING_SYBI_ID' : SybiVendingValidationCode::INVALID_SYBI_ID->value,
                $missing ? 'Missing required id_sucursal.' : 'Expected a positive integer id_sucursal.',
            );
        }

        $codes = [];
        $identifier = $this->sourceString($record, 'identificador_vending', 100, $codes, true);
        if ($identifier !== null && str_starts_with(mb_strtoupper($identifier), 'VM-DEMO-')) {
            return $this->invalid(
                $index,
                $record,
                'identificador_vending',
                SybiVendingValidationCode::DEMO_IDENTIFIER_RESERVED->value,
                'The VM-DEMO-* identifier range is reserved for local demo data.',
            );
        }

        $name = $this->sourceString($record, 'nombre_sucursal', 255, $codes);
        $location = $record['ubicacion'] ?? null;
        if ($location === null) {
            $location = [];
        } elseif (! is_array($location)) {
            $codes[] = SybiVendingValidationCode::INVALID_LOCATION_STRUCTURE;
            $location = [];
        }

        $addressLine = $this->sourceString($location, 'calle', 255, $codes);
        $neighborhood = $this->sourceString($location, 'colonia', 255, $codes);
        $postalCode = $this->sourceString($location, 'codigo_postal', 20, $codes);
        $fullAddress = $this->sourceString($location, 'direccion_completa', 2000, $codes);
        $cityId = $this->nullablePositiveInteger($location, 'id_ciudad', $codes);
        $stateId = $this->nullablePositiveInteger($location, 'id_estado', $codes);

        [$latitude, $latitudeState] = $this->coordinate($record, 'latitud', -90, 90);
        [$longitude, $longitudeState] = $this->coordinate($record, 'longitud', -180, 180);
        if ($latitudeState === 'MISSING' || $longitudeState === 'MISSING') {
            $codes[] = SybiVendingValidationCode::MISSING_COORDINATES;
        }
        if ($latitudeState === 'INVALID' || $longitudeState === 'INVALID') {
            $codes[] = SybiVendingValidationCode::INVALID_COORDINATES;
        }
        if ($latitude === 0.0 && $longitude === 0.0) {
            $codes[] = SybiVendingValidationCode::ZERO_COORDINATES;
        }

        return SybiVendingRecordMapResult::accepted(new SybiVendingSourceCandidate(
            index: $index,
            sybiId: (string) $sybiId,
            vendingIdentifier: $identifier,
            name: $name,
            addressLine: $addressLine,
            neighborhood: $neighborhood,
            postalCode: $postalCode,
            cityId: $cityId,
            stateId: $stateId,
            fullAddress: $fullAddress,
            latitude: $latitude,
            longitude: $longitude,
            validationCodes: $codes,
        ));
    }

    /** @param array<int, SybiVendingValidationCode> $codes */
    private function sourceString(
        array $source,
        string $key,
        int $maximumLength,
        array &$codes,
        bool $identifier = false,
    ): ?string {
        if (! array_key_exists($key, $source) || $source[$key] === null || $source[$key] === '') {
            if ($identifier) {
                $codes[] = SybiVendingValidationCode::MISSING_VENDING_IDENTIFIER;
            }

            return null;
        }

        if (! is_string($source[$key])) {
            $codes[] = $identifier
                ? SybiVendingValidationCode::INVALID_VENDING_IDENTIFIER
                : SybiVendingValidationCode::INVALID_SOURCE_FIELD;

            return null;
        }

        $value = trim($source[$key]);
        if ($value === '') {
            if ($identifier) {
                $codes[] = SybiVendingValidationCode::MISSING_VENDING_IDENTIFIER;
            }

            return null;
        }
        if (mb_strlen($value) > $maximumLength) {
            $codes[] = $identifier
                ? SybiVendingValidationCode::INVALID_VENDING_IDENTIFIER
                : SybiVendingValidationCode::INVALID_SOURCE_FIELD;

            return null;
        }

        return $value;
    }

    /** @param array<int, SybiVendingValidationCode> $codes */
    private function nullablePositiveInteger(array $source, string $key, array &$codes): ?int
    {
        if (! array_key_exists($key, $source) || $source[$key] === null || $source[$key] === '') {
            return null;
        }

        $value = $this->positiveInteger($source[$key]);
        if ($value === null) {
            $codes[] = SybiVendingValidationCode::INVALID_SOURCE_FIELD;
        }

        return $value;
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);

            return $integer !== false ? $integer : null;
        }

        return null;
    }

    /** @return array{?float, 'VALID'|'MISSING'|'INVALID'} */
    private function coordinate(array $record, string $key, float $minimum, float $maximum): array
    {
        if (! array_key_exists($key, $record) || $record[$key] === null || $record[$key] === '') {
            return [null, 'MISSING'];
        }
        if (! is_numeric($record[$key])) {
            return [null, 'INVALID'];
        }

        $value = (float) $record[$key];
        if (! is_finite($value) || $value < $minimum || $value > $maximum) {
            return [null, 'INVALID'];
        }

        return [$value, 'VALID'];
    }

    private function invalid(
        int $index,
        mixed $record,
        ?string $field,
        string $code,
        string $reason,
    ): SybiVendingRecordMapResult {
        return SybiVendingRecordMapResult::rejected(
            SybiVendingRejection::fromRecord($index, $record, $field, $code, $reason),
        );
    }
}
