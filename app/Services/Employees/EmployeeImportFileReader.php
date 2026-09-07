<?php

namespace App\Services\Employees;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use SimpleXMLElement;
use ZipArchive;

class EmployeeImportFileReader
{
    public function __construct(private readonly EmployeeIdentityNormalizer $normalizer) {}

    public function read(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $mime = $file->getMimeType();
        $allowed = $extension === 'csv'
            ? ['text/plain', 'text/csv', 'application/csv', 'application/x-csv']
            : ['application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        if (! in_array($extension, ['csv', 'xlsx'], true) || ! in_array($mime, $allowed, true)) {
            $this->fail('INVALID_FILE_TYPE');
        }
        if ($file->getSize() > max(1, (int) config('employees.import.max_file_kb', 5120)) * 1024) {
            $this->fail('FILE_TOO_LARGE');
        }
        $table = $extension === 'csv' ? $this->csv($file->getPathname()) : $this->xlsx($file->getPathname());
        if (count($table) < 2) {
            $this->fail('EMPTY_FILE');
        }
        $header = array_shift($table);
        $mapping = [];
        $detected = [];
        foreach ($header['cells'] as $index => $name) {
            foreach (EmployeeIdentityNormalizer::HEADERS as $field => $aliases) {
                if (in_array($this->normalizer->header($name), array_map($this->normalizer->header(...), $aliases), true)) {
                    if (isset($mapping[$field])) {
                        $this->fail('AMBIGUOUS_HEADER');
                    }
                    $mapping[$field] = $index;
                    $detected[$field] = $this->normalizer->text($name);
                }
            }
        }
        if (count($mapping) !== 3) {
            $this->fail('REQUIRED_HEADERS_MISSING');
        }
        $rows = [];
        foreach ($table as $line) {
            $cells = $line['cells'];
            $rows[] = [
                'row_number' => $line['row_number'],
                'employee_number' => $this->normalizer->text($cells[$mapping['employee_number']] ?? null),
                'full_name' => $this->normalizer->text($cells[$mapping['full_name']] ?? null),
                'status' => $this->normalizer->status($cells[$mapping['status']] ?? null),
                'formula' => $line['formula'] ?? false,
            ];
        }

        return ['mapping' => $detected, 'rows' => $rows];
    }

    private function csv(string $path): array
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            $this->fail('UNREADABLE_FILE');
        }
        $table = [];
        try {
            $row = 0;
            while (($cells = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                $row++;
                if (count($cells) > 100) {
                    $this->fail('TOO_MANY_COLUMNS');
                }
                foreach ($cells as $cell) {
                    if ($cell !== null && (! mb_check_encoding($cell, 'UTF-8') || str_contains($cell, "\0"))) {
                        $this->fail('INVALID_ENCODING');
                    }
                }
                if (count(array_filter($cells, fn ($cell) => $this->normalizer->text($cell) !== null)) === 0) {
                    continue;
                }
                $table[] = ['row_number' => $row, 'cells' => $cells];
                $this->checkRowLimit(count($table));
            }
        } finally {
            fclose($stream);
        }

        return $table;
    }

    private function xlsx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            $this->fail('MALFORMED_XLSX');
        }
        try {
            if ($zip->numFiles > max(1, (int) config('employees.import.xlsx_max_entries', 256))) {
                $this->fail('XLSX_ARCHIVE_LIMIT');
            }
            $bytes = 0;
            $worksheets = [];
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                $name = $entry['name'];
                if (isset($names[$name]) || preg_match('~(^/|\\\\|\.\.|vba|macros|externalLinks|embeddings|activeX)~i', $name)) {
                    $this->fail('UNSAFE_XLSX');
                }
                $names[$name] = true;
                $bytes += $entry['size'];
                if ($bytes > max(1024, (int) config('employees.import.xlsx_max_uncompressed_bytes', 52428800))
                    || ($entry['size'] > 1048576 && $entry['size'] / max(1, $entry['comp_size']) > 100)) {
                    $this->fail('XLSX_ARCHIVE_LIMIT');
                }
                if (preg_match('~^xl/worksheets/[^/]+\.xml$~', $name)) {
                    $worksheets[] = $name;
                }
                if (preg_match('/\.(xml|rels)$/i', $name)) {
                    $content = $zip->getFromIndex($i);
                    if (! is_string($content) || preg_match('/<!DOCTYPE|<!ENTITY|macroEnabled|vbaProject|TargetMode\s*=\s*["\x27]External/i', $content)) {
                        $this->fail('UNSAFE_XLSX');
                    }
                }
            }
            if (! isset($names['[Content_Types].xml'], $names['xl/workbook.xml']) || count($worksheets) !== 1) {
                $this->fail('XLSX_REQUIRES_ONE_SHEET');
            }
            $strings = [];
            if (isset($names['xl/sharedStrings.xml'])) {
                foreach ($this->xml($zip->getFromName('xl/sharedStrings.xml'))->si as $item) {
                    $strings[] = $this->richText($item);
                }
            }
            $formats = [];
            if (isset($names['xl/styles.xml'])) {
                $styles = $this->xml($zip->getFromName('xl/styles.xml'));
                $codes = [];
                foreach ($styles->numFmts->numFmt ?? [] as $format) {
                    $codes[(int) $format['numFmtId']] = (string) $format['formatCode'];
                }
                foreach ($styles->cellXfs->xf ?? [] as $style) {
                    $formats[] = $codes[(int) $style['numFmtId']] ?? null;
                }
            }
            $table = [];
            $seenRows = [];
            foreach ($this->xml($zip->getFromName($worksheets[0]))->sheetData->row as $row) {
                $rowNumber = (int) $row['r'];
                if ($rowNumber < 1 || isset($seenRows[$rowNumber])) {
                    $this->fail('MALFORMED_XLSX');
                }
                $seenRows[$rowNumber] = true;
                $cells = [];
                $formula = false;
                foreach ($row->c as $cell) {
                    if (! preg_match('/^([A-Z]{1,3})([1-9][0-9]*)$/', (string) $cell['r'], $match) || (int) $match[2] !== $rowNumber) {
                        $this->fail('MALFORMED_XLSX');
                    }
                    $column = 0;
                    foreach (str_split($match[1]) as $letter) {
                        $column = $column * 26 + ord($letter) - 64;
                    }
                    if ($column > 100 || array_key_exists($column - 1, $cells)) {
                        $this->fail('TOO_MANY_OR_DUPLICATE_COLUMNS');
                    }
                    $formula = $formula || isset($cell->f);
                    $raw = match ((string) $cell['t']) {
                        's' => $strings[(int) $cell->v] ?? null,
                        'inlineStr' => $this->richText($cell->is),
                        default => isset($cell->v) ? (string) $cell->v : null,
                    };
                    $format = $formats[(int) $cell['s']] ?? null;
                    if (in_array((string) $cell['t'], ['', 'n'], true) && is_string($raw) && ctype_digit($raw)
                        && is_string($format) && preg_match('/^0{1,120}$/', $format)) {
                        $raw = str_pad($raw, strlen($format), '0', STR_PAD_LEFT);
                    }
                    $cells[$column - 1] = $raw;
                }
                if ($formula || count(array_filter($cells, fn ($cell) => $this->normalizer->text($cell) !== null)) > 0) {
                    if ($table === [] && $formula) {
                        $this->fail('FORMULA_HEADER');
                    }
                    $table[] = ['row_number' => $rowNumber, 'cells' => $cells, 'formula' => $formula];
                    $this->checkRowLimit(count($table));
                }
            }

            return $table;
        } finally {
            $zip->close();
        }
    }

    private function xml(string|false $value): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = is_string($value) ? simplexml_load_string($value, SimpleXMLElement::class, LIBXML_NONET) : false;
            if ($xml === false) {
                $this->fail('MALFORMED_XLSX');
            }

            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function richText(SimpleXMLElement $item): string
    {
        $value = (string) $item->t;
        foreach ($item->r as $run) {
            $value .= (string) $run->t;
        }

        return $value;
    }

    private function checkRowLimit(int $count): void
    {
        if ($count > max(1, (int) config('employees.import.max_rows', 5000)) + 1) {
            $this->fail('ROW_LIMIT_EXCEEDED');
        }
    }

    private function fail(string $code): never
    {
        throw ValidationException::withMessages(['file' => $code]);
    }
}
