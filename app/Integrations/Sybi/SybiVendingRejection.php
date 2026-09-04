<?php

namespace App\Integrations\Sybi;

final readonly class SybiVendingRejection
{
    public function __construct(
        public int $index,
        public ?string $sybiId,
        public ?string $vendingId,
        public ?string $field,
        public string $errorCode,
        public string $reason,
    ) {}

    public static function fromRecord(
        int $index,
        mixed $record,
        ?string $field,
        string $errorCode,
        string $reason,
    ): self {
        $record = is_array($record) ? $record : [];

        return new self(
            $index,
            self::safeIdentifier($record['id_sucursal'] ?? null),
            self::safeIdentifier($record['identificador_vending'] ?? null),
            $field,
            $errorCode,
            $reason,
        );
    }

    /** @return array{int,string,string,string,string,string} */
    public function consoleRow(): array
    {
        return [
            $this->index,
            $this->sybiId ?? '—',
            $this->vendingId ?? '—',
            $this->field ?? '—',
            $this->errorCode,
            $this->reason,
        ];
    }

    private static function safeIdentifier(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $value = preg_replace('/[^\pL\pN._:\/-]+/u', '?', $value) ?? '';
        $value = mb_substr($value, 0, 100);

        return $value !== '' ? $value : null;
    }
}
