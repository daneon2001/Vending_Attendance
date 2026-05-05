<?php

namespace App\Support;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class TabularDataReader
{
    /**
     * @return array<int, array<string, string|null>>
     */
    public function readRows(string $path, ?string $extension = null): array
    {
        if (! is_file($path)) {
            return [];
        }

        $extension = strtolower((string) ($extension ?: pathinfo($path, PATHINFO_EXTENSION)));
        $extension = preg_replace('/^\./', '', $extension) ?? $extension;

        return match ($extension) {
            'csv', 'txt' => $this->readCsvRows($path),
            'xlsx' => $this->readXlsxRows($path),
            default => throw new RuntimeException("Formato no soportado: {$extension}"),
        };
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function readCsvRows(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        $headers = fgetcsv($handle, 0, ',', '"', '\\');
        if (! is_array($headers)) {
            fclose($handle);

            return [];
        }

        $normalizedHeaders = array_map(fn (mixed $header) => $this->normalizeHeader($header), $headers);
        $rows = [];

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
                    ? $this->normalizeValue($data[$index])
                    : null;
            }

            if ($row !== []) {
                $rows[] = $row;
            }
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function readXlsxRows(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return [];
        }

        try {
            $sharedStrings = $this->readSharedStrings($zip);
            $worksheetPath = $this->resolveFirstWorksheetPath($zip);
            if ($worksheetPath === null) {
                return [];
            }

            $sheetXml = $zip->getFromName($worksheetPath);
            if (! is_string($sheetXml) || trim($sheetXml) === '') {
                return [];
            }

            $sheet = simplexml_load_string($sheetXml);
            if (! $sheet instanceof SimpleXMLElement || ! isset($sheet->sheetData)) {
                return [];
            }

            $table = [];
            foreach ($sheet->sheetData->row as $rowNode) {
                $row = [];
                foreach ($rowNode->c as $cellNode) {
                    $cellRef = (string) ($cellNode['r'] ?? '');
                    $columnIndex = $this->columnRefToIndex($cellRef);
                    if ($columnIndex === null) {
                        continue;
                    }

                    $row[$columnIndex] = $this->extractCellValue($cellNode, $sharedStrings);
                }

                if ($row === []) {
                    continue;
                }

                ksort($row);
                $table[] = $row;
            }

            if ($table === []) {
                return [];
            }

            $headerRow = $table[0];
            ksort($headerRow);

            $headersByColumn = [];
            foreach ($headerRow as $columnIndex => $headerValue) {
                $headersByColumn[$columnIndex] = $this->normalizeHeader($headerValue);
            }

            $rows = [];
            foreach (array_slice($table, 1) as $rawRow) {
                $record = [];
                ksort($rawRow);

                foreach ($headersByColumn as $columnIndex => $header) {
                    if ($header === '') {
                        continue;
                    }

                    $record[$header] = array_key_exists($columnIndex, $rawRow)
                        ? $this->normalizeValue($rawRow[$columnIndex])
                        : null;
                }

                if ($record !== []) {
                    $rows[] = $record;
                }
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array<int, string>
     */
    private function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if (! is_string($xml) || trim($xml) === '') {
            return [];
        }

        $shared = simplexml_load_string($xml);
        if (! $shared instanceof SimpleXMLElement) {
            return [];
        }

        $strings = [];
        foreach ($shared->si as $si) {
            if (isset($si->t)) {
                $strings[] = (string) $si->t;
                continue;
            }

            $text = '';
            if (isset($si->r)) {
                foreach ($si->r as $run) {
                    $text .= (string) ($run->t ?? '');
                }
            }

            $strings[] = $text;
        }

        return $strings;
    }

    private function resolveFirstWorksheetPath(ZipArchive $zip): ?string
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if (! is_string($workbookXml) || ! is_string($relsXml)) {
            return $zip->getFromName('xl/worksheets/sheet1.xml') !== false
                ? 'xl/worksheets/sheet1.xml'
                : null;
        }

        $workbook = simplexml_load_string($workbookXml);
        $rels = simplexml_load_string($relsXml);
        if (! $workbook instanceof SimpleXMLElement || ! $rels instanceof SimpleXMLElement) {
            return $zip->getFromName('xl/worksheets/sheet1.xml') !== false
                ? 'xl/worksheets/sheet1.xml'
                : null;
        }

        $relNamespaces = $rels->getNamespaces(true);
        $rels->registerXPathNamespace('rel', $relNamespaces[''] ?? 'http://schemas.openxmlformats.org/package/2006/relationships');

        $workbookNamespaces = $workbook->getNamespaces(true);
        $workbook->registerXPathNamespace('main', $workbookNamespaces[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $workbook->registerXPathNamespace('r', $workbookNamespaces['r'] ?? 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        $sheetNodes = $workbook->xpath('//main:sheets/main:sheet');
        if (! is_array($sheetNodes) || $sheetNodes === []) {
            return $zip->getFromName('xl/worksheets/sheet1.xml') !== false
                ? 'xl/worksheets/sheet1.xml'
                : null;
        }

        $firstSheet = $sheetNodes[0];
        $relationshipId = (string) ($firstSheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '');
        if ($relationshipId === '') {
            return $zip->getFromName('xl/worksheets/sheet1.xml') !== false
                ? 'xl/worksheets/sheet1.xml'
                : null;
        }

        $relationNodes = $rels->xpath("//rel:Relationship[@Id='{$relationshipId}']");
        if (! is_array($relationNodes) || $relationNodes === []) {
            return $zip->getFromName('xl/worksheets/sheet1.xml') !== false
                ? 'xl/worksheets/sheet1.xml'
                : null;
        }

        $target = (string) ($relationNodes[0]['Target'] ?? '');
        if ($target === '') {
            return $zip->getFromName('xl/worksheets/sheet1.xml') !== false
                ? 'xl/worksheets/sheet1.xml'
                : null;
        }

        $target = str_replace('\\', '/', $target);
        $target = ltrim($target, '/');
        $candidate = str_starts_with($target, 'xl/')
            ? $target
            : 'xl/'.$target;

        return $zip->getFromName($candidate) !== false ? $candidate : null;
    }

    /**
     * @param  array<int, string>  $sharedStrings
     */
    private function extractCellValue(SimpleXMLElement $cellNode, array $sharedStrings): ?string
    {
        $type = (string) ($cellNode['t'] ?? '');

        if ($type === 'inlineStr' && isset($cellNode->is->t)) {
            return (string) $cellNode->is->t;
        }

        if (! isset($cellNode->v)) {
            return null;
        }

        $raw = (string) $cellNode->v;
        if ($type === 's') {
            $index = (int) $raw;

            return $sharedStrings[$index] ?? null;
        }

        return $raw;
    }

    private function columnRefToIndex(string $cellRef): ?int
    {
        if ($cellRef === '') {
            return null;
        }

        if (! preg_match('/^([A-Z]+)/i', $cellRef, $matches)) {
            return null;
        }

        $letters = strtoupper($matches[1]);
        $index = 0;
        $length = strlen($letters);
        for ($i = 0; $i < $length; $i++) {
            $index = ($index * 26) + (ord($letters[$i]) - ord('A') + 1);
        }

        return $index - 1;
    }

    private function normalizeHeader(mixed $header): string
    {
        $value = (string) $header;
        $value = str_replace("\xEF\xBB\xBF", '', $value);
        $value = preg_replace('/^\x{FEFF}/u', '', $value) ?? $value;
        $value = str_replace(["\u{00A0}", "\u{200B}", "\u{200C}", "\u{200D}", "\u{2060}"], ' ', $value);
        $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value);

        return strtoupper($value);
    }

    private function normalizeValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
