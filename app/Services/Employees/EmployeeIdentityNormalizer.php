<?php

namespace App\Services\Employees;

use Illuminate\Support\Str;

class EmployeeIdentityNormalizer
{
    // The first aliases come from the audited Fortia export; the rest are the pilot contract.
    public const HEADERS = [
        'employee_number' => ['CLA_TRAB', 'numero_empleado', 'employee_number', 'Numero de empleado', 'No. empleado', 'Número de empleado'],
        'full_name' => ['NOMBRE', 'Nombre completo', 'Empleado', 'full_name'],
        'status' => ['ESTATUS_TRABAJADOR', 'Estado', 'Activo', 'status'],
    ];

    public function text(mixed $value): ?string
    {
        if ($value === null || (! is_string($value) && ! is_int($value) && ! is_bool($value))) {
            return null;
        }
        $value = (string) $value;
        if (! mb_check_encoding($value, 'UTF-8')) {
            return null;
        }
        $value = preg_replace('/^\x{FEFF}/u', '', $value) ?? $value;
        $value = preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $value) ?? $value;
        if (class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_C) ?: $value;
        }

        return $value === '' ? null : $value;
    }

    public function header(mixed $value): string
    {
        return strtoupper(preg_replace('/[\s._]+/u', '', Str::ascii($this->text($value) ?? '')) ?? '');
    }

    public function status(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? 'A' : 'B';
        }

        return match (mb_strtoupper($this->text($value) ?? '')) {
            'A', 'ACTIVO', 'ACTIVE', '1', 'TRUE' => 'A',
            'B', 'BAJA', 'INACTIVO', 'INACTIVE', '0', 'FALSE' => 'B',
            default => null,
        };
    }

    public function errors(array $values, int $row): array
    {
        $errors = [];
        foreach (['employee_number' => 120, 'full_name' => 255, 'status' => 20] as $field => $max) {
            $value = $values[$field] ?? null;
            $code = null;
            if ($value === null || $value === '') {
                $code = $field === 'status' ? 'INVALID_STATUS' : 'REQUIRED';
            } elseif (mb_strlen($value) > $max) {
                $code = 'TOO_LONG';
            } elseif (preg_match('/[\x00-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{206F}]/u', $value)) {
                $code = 'CONTROL_CHARACTER';
            } elseif (preg_match('/^[=+@-]/u', $value)) {
                $code = 'FORMULA_NOT_ALLOWED';
            }
            if ($code) {
                $errors[] = ['row_number' => $row, 'field' => $field, 'code' => $code, 'reason' => match ($code) {
                    'REQUIRED' => 'El valor es obligatorio y debe ser texto válido.',
                    'INVALID_STATUS' => 'Usa ACTIVO/A o INACTIVO/BAJA/B.',
                    'TOO_LONG' => 'El valor excede la longitud permitida.',
                    'FORMULA_NOT_ALLOWED' => 'Las fórmulas no se admiten en el catálogo.',
                    default => 'El valor contiene caracteres de control.',
                }];
            }
        }

        return $errors;
    }
}
