<?php

namespace Database\Seeders;

use App\Models\Employee;
use Database\Seeders\Concerns\ReadsCsvRows;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class EmployeesFromSourceSeeder extends Seeder
{
    use ReadsCsvRows;

    public function run(): void
    {
        if (! Schema::hasTable('employees')) {
            $this->command?->warn('Tabla employees no existe. Se omite EmployeesFromSourceSeeder.');

            return;
        }

        $sourcePath = $this->resolveSourcePath();
        if ($sourcePath === null) {
            $this->command?->warn('No se encontro archivo fuente de colaboradores.');

            return;
        }

        $rows = $this->readCsvRows($sourcePath);
        if ($rows === []) {
            $this->command?->warn("Archivo fuente sin filas: {$sourcePath}");

            return;
        }

        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $skippedInvalid = 0;
        $skippedUnchanged = 0;

        foreach ($rows as $row) {
            $fortiaEmployeeId = $this->toNullableInt($this->csvValue($row, 'CLA_TRAB'));
            if ($fortiaEmployeeId === null || $fortiaEmployeeId <= 0) {
                $skipped++;
                $skippedInvalid++;
                continue;
            }

            $payload = $this->buildEmployeePayload($row);
            $payload = array_filter($payload, fn ($value) => $value !== null);

            $employee = Employee::query()->updateOrCreate(
                ['fortia_employee_id' => $fortiaEmployeeId],
                $payload
            );

            if ($employee->wasRecentlyCreated) {
                $inserted++;
                continue;
            }

            if ($employee->wasChanged()) {
                $updated++;
                continue;
            }

            $skipped++;
            $skippedUnchanged++;
        }

        $summary = [
            'source' => str_replace(base_path().'\\', '', $sourcePath),
            'processed' => count($rows),
            'inserted' => $inserted,
            'updated' => $updated,
            'skipped' => $skipped,
            'skipped_invalid' => $skippedInvalid,
            'skipped_unchanged' => $skippedUnchanged,
        ];

        $this->command?->info('EmployeesFromSourceSeeder resumen: '.json_encode($summary, JSON_UNESCAPED_UNICODE));

        $this->call([
            OperationalCatalogsFromEmployeesSeeder::class,
            EmployeeDetailsSeeder::class,
        ]);
    }

    private function resolveSourcePath(): ?string
    {
        $candidates = [
            base_path('database/datos/colaboradores_fuente_completa.csv'),
            base_path('database/datos/colaboradores_base.csv'),
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<string, string|null> $row
     * @return array<string, mixed>
     */
    private function buildEmployeePayload(array $row): array
    {
        $fullName = $this->csvValue($row, 'NOMBRE');
        $nameParts = $this->splitFullName($fullName);

        return [
            'name' => $nameParts['name'],
            'last_name' => $nameParts['last_name'],
            'second_last_name' => $nameParts['second_last_name'],
            'full_name' => $fullName,
            'status' => $this->normalizeStatus($this->csvValue($row, 'ESTATUS_TRABAJADOR')),
            'rfc' => $this->csvValue($row, 'RFC'),
            'imss_number' => $this->csvValue($row, 'NUM_IMSS'),
            'curp' => $this->csvValue($row, 'CURP'),
            'email_company' => $this->csvValue($row, 'CORREO_CORPORATIVO'),
            'company_id' => $this->toNullableInt($this->csvValue($row, 'CLA_RAZON_SOCIAL')),
            'company_name' => $this->csvValue($row, 'NOM_RAZON_SOCIAL'),
            'department_id' => $this->toNullableInt($this->csvValue($row, 'CLA_DEPTO')),
            'department_name' => $this->csvValue($row, 'NOM_DEPARTAMENTO'),
            'base_location_id' => $this->toNullableInt($this->csvValue($row, 'CLA_UBICACION')),
            'base_location_name' => $this->csvValue($row, 'NOM_UBICACION'),
        ];
    }

    /**
     * @return array{name: ?string, last_name: ?string, second_last_name: ?string}
     */
    private function splitFullName(?string $fullName): array
    {
        $fullName = trim((string) $fullName);
        if ($fullName === '') {
            return [
                'name' => null,
                'last_name' => null,
                'second_last_name' => null,
            ];
        }

        $parts = preg_split('/\s+/', $fullName) ?: [];
        $parts = array_values(array_filter($parts, fn ($part) => $part !== ''));
        $count = count($parts);

        if ($count >= 3) {
            return [
                'last_name' => $parts[0],
                'second_last_name' => $parts[1],
                'name' => implode(' ', array_slice($parts, 2)),
            ];
        }

        if ($count === 2) {
            return [
                'last_name' => $parts[0],
                'second_last_name' => null,
                'name' => $parts[1],
            ];
        }

        return [
            'last_name' => null,
            'second_last_name' => null,
            'name' => $parts[0] ?? null,
        ];
    }

    private function normalizeStatus(?string $rawStatus): string
    {
        $status = strtoupper(trim((string) $rawStatus));

        if ($status === '') {
            return 'A';
        }

        if (in_array($status, ['A', 'ACTIVO', 'ACTIVE', 'ALTA', 'VIGENTE'], true)) {
            return 'A';
        }

        if (in_array($status, ['B', 'BAJA', 'INACTIVO', 'INACTIVA', 'INACTIVE', 'SUSPENDIDO'], true)) {
            return 'B';
        }

        return 'A';
    }

    private function toNullableInt(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        $normalized = str_replace(',', '', $normalized);
        if (! is_numeric($normalized)) {
            return null;
        }

        return (int) round((float) $normalized);
    }
}
