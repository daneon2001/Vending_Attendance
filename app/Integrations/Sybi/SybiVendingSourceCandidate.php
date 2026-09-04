<?php

namespace App\Integrations\Sybi;

use App\Enums\Vending\SybiVendingSourceStatus;
use App\Enums\Vending\SybiVendingValidationCode;
use App\Enums\Vending\SybiVendingValidationStatus;

final class SybiVendingSourceCandidate
{
    /** @var array<string, SybiVendingValidationCode> */
    private array $validationCodes = [];

    public function __construct(
        public readonly int $index,
        public readonly string $sybiId,
        public readonly ?string $vendingIdentifier,
        public readonly ?string $name,
        public readonly ?string $addressLine,
        public readonly ?string $neighborhood,
        public readonly ?string $postalCode,
        public readonly ?int $cityId,
        public readonly ?int $stateId,
        public readonly ?string $fullAddress,
        public readonly ?float $latitude,
        public readonly ?float $longitude,
        array $validationCodes = [],
    ) {
        foreach ($validationCodes as $code) {
            $this->addValidationCode($code);
        }
    }

    public function addValidationCode(SybiVendingValidationCode $code): void
    {
        $this->validationCodes[$code->value] = $code;
    }

    /** @return list<string> */
    public function validationCodeValues(): array
    {
        $values = array_keys($this->validationCodes);
        sort($values, SORT_STRING);

        return $values;
    }

    public function hasCode(SybiVendingValidationCode $code): bool
    {
        return isset($this->validationCodes[$code->value]);
    }

    public function hasLocationIssue(): bool
    {
        return $this->hasCode(SybiVendingValidationCode::ZERO_COORDINATES)
            || $this->hasCode(SybiVendingValidationCode::MISSING_COORDINATES)
            || $this->hasCode(SybiVendingValidationCode::INVALID_COORDINATES);
    }

    public function validationStatus(): SybiVendingValidationStatus
    {
        if ($this->hasCode(SybiVendingValidationCode::DUPLICATE_VENDING_IDENTIFIER)) {
            return SybiVendingValidationStatus::IDENTIFIER_CONFLICT;
        }

        if ($this->hasLocationIssue()) {
            return SybiVendingValidationStatus::INCOMPLETE_LOCATION;
        }

        if ($this->validationCodes !== []) {
            return SybiVendingValidationStatus::INVALID;
        }

        return SybiVendingValidationStatus::READY;
    }

    public function isReady(): bool
    {
        return $this->validationStatus() === SybiVendingValidationStatus::READY;
    }

    /** @return array<string, mixed> */
    public function sourceAttributes(): array
    {
        return [
            'sybi_id' => $this->sybiId,
            'identificador_vending' => $this->vendingIdentifier,
            'name' => $this->name,
            'address_line' => $this->addressLine,
            'neighborhood' => $this->neighborhood,
            'postal_code' => $this->postalCode,
            'sybi_city_id' => $this->cityId,
            'sybi_state_id' => $this->stateId,
            'sybi_full_address' => $this->fullAddress,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'source_status' => SybiVendingSourceStatus::PRESENT->value,
            'validation_status' => $this->validationStatus()->value,
            'validation_codes' => $this->validationCodeValues(),
            'payload_hash' => $this->payloadHash(),
        ];
    }

    /** @return array<string, mixed> */
    public function machineAttributes(): array
    {
        return [
            'sybi_id' => $this->sybiId,
            'machine_code' => $this->vendingIdentifier,
            'name' => $this->name,
            'address_line' => $this->addressLine,
            'neighborhood' => $this->neighborhood,
            'postal_code' => $this->postalCode,
            'sybi_city_id' => $this->cityId,
            'sybi_state_id' => $this->stateId,
            'sybi_full_address' => $this->fullAddress,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
        ];
    }

    public function payloadHash(): string
    {
        $canonical = [
            'sybi_id' => $this->sybiId,
            'identificador_vending' => $this->vendingIdentifier,
            'name' => $this->name,
            'address_line' => $this->addressLine,
            'neighborhood' => $this->neighborhood,
            'postal_code' => $this->postalCode,
            'sybi_city_id' => $this->cityId,
            'sybi_state_id' => $this->stateId,
            'sybi_full_address' => $this->fullAddress,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
        ];

        return hash('sha256', json_encode($canonical, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
