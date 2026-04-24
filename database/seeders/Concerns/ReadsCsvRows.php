<?php

namespace Database\Seeders\Concerns;

trait ReadsCsvRows
{
    /**
     * @return array<int, array<string, string|null>>
     */
    protected function readCsvRows(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        $rows = [];
        $headers = fgetcsv($handle, 0, ',', '"', '\\');
        if (! is_array($headers)) {
            fclose($handle);

            return [];
        }

        $normalizedHeaders = array_map(fn ($header) => $this->normalizeCsvHeader($header), $headers);

        while (($data = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if ($data === [null]) {
                continue;
            }

            $row = [];

            foreach ($normalizedHeaders as $index => $header) {
                if ($header === '') {
                    continue;
                }

                $row[$header] = array_key_exists($index, $data)
                    ? $this->normalizeCsvValue($data[$index])
                    : null;
            }

            if ($row !== []) {
                $rows[] = $row;
            }
        }

        fclose($handle);

        return $rows;
    }

    protected function csvValue(array $row, string $column): ?string
    {
        $normalizedColumn = $this->normalizeCsvHeader($column);
        $value = $row[$normalizedColumn] ?? null;

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function normalizeCsvHeader(mixed $header): string
    {
        $value = trim((string) $header);
        $value = str_replace("\xEF\xBB\xBF", '', $value);
        $value = preg_replace('/^\x{FEFF}/u', '', $value) ?? $value;

        return strtoupper($value);
    }

    private function normalizeCsvValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
