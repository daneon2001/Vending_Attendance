<?php

namespace App\Exports;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class EmployeesCatalogExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithStyles
{
    public function __construct(
        private readonly Builder $query,
    ) {
    }

    public function query(): Builder
    {
        return $this->query;
    }

    public function headings(): array
    {
        return [
            'Clave empleado',
            'Nombre completo',
            'Empresa',
            'Unidad base',
            'Alcance de unidades',
            'Estatus',
            'Puede checar en todas las unidades',
            'Huella registrada',
            'Face ID registrado',
            'Fecha de alta/importacion',
            'Ultima actualizacion',
        ];
    }

    /**
     * @param  \App\Models\Employee  $employee
     */
    public function map($employee): array
    {
        return [
            $employee->visibleEmployeeKey() ?? 'Sin clave',
            $this->employeeFullName($employee),
            $this->employeeCompany($employee),
            $employee->baseLocation?->name ?? $employee->base_location_name ?? 'Sin unidad',
            $this->locationScopeLabel($employee),
            $this->statusLabel((string) $employee->status),
            $employee->can_check_all_branches ? 'Si' : 'No',
            $employee->has_fingerprint ? 'Si' : 'No',
            (bool) ($employee->has_face_enrollment ?? false) ? 'Si' : 'No',
            optional($employee->created_at)->timezone(config('app.timezone'))->format('Y-m-d H:i:s') ?? '',
            optional($employee->updated_at)->timezone(config('app.timezone'))->format('Y-m-d H:i:s') ?? '',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => '0F172A']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'E2E8F0'],
                ],
            ],
        ];
    }

    private function employeeFullName(Employee $employee): string
    {
        $fullName = trim((string) ($employee->full_name ?? ''));

        if ($fullName !== '') {
            return $fullName;
        }

        return trim(implode(' ', array_filter([
            $employee->name,
            $employee->last_name,
            $employee->second_last_name,
        ]))) ?: 'Sin nombre';
    }

    private function employeeCompany(Employee $employee): string
    {
        $companyName = trim((string) ($employee->company_name ?? ''));

        if ($companyName !== '') {
            return $companyName;
        }

        return trim((string) ($employee->company?->name ?? '')) ?: 'Sin empresa';
    }

    private function locationScopeLabel(Employee $employee): string
    {
        if ($employee->can_check_all_branches) {
            return 'Todas las unidades';
        }

        if (! $employee->relationLoaded('allowedLocations')) {
            return 'Solo unidad base';
        }

        $locations = $employee->allowedLocations
            ->map(function ($location): string {
                $name = trim((string) ($location->name ?? ''));
                $code = trim((string) ($location->code ?? ''));

                if ($name !== '' && $code !== '') {
                    return "{$name} ({$code})";
                }

                return $name !== '' ? $name : ($code !== '' ? $code : (string) $location->id);
            })
            ->filter()
            ->values();

        if ($locations->isNotEmpty()) {
            return $locations->implode(', ');
        }

        return 'Solo unidad base';
    }

    private function statusLabel(string $status): string
    {
        return in_array(strtolower($status), ['a', 'active'], true)
            ? 'Activo'
            : 'Baja';
    }
}
