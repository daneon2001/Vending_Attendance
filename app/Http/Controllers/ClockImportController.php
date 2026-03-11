<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClockImportRequest;
use App\Http\Resources\ClockResource;
use App\Models\Clock;
use App\Models\Company;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ClockImportController extends Controller
{
    /**
     * Importa relojes desde un archivo CSV simple.
     */
    public function __invoke(ClockImportRequest $request): JsonResponse
    {
        $handle = fopen($request->file('file')->getRealPath(), 'r');

        if (! $handle) {
            return response()->json([
                'message' => 'No fue posible leer el archivo proporcionado.',
            ], 422);
        }

        $headers = fgetcsv($handle, 0, ',');

        if (! $headers) {
            return response()->json([
                'message' => 'El archivo no contiene encabezados válidos.',
            ], 422);
        }

        $headers = array_map(fn ($value) => strtolower(trim($value)), $headers);
        $required = ['clock_name', 'serial_number', 'ip_address'];

        foreach ($required as $requiredHeader) {
            if (! in_array($requiredHeader, $headers, true)) {
                return response()->json([
                    'message' => 'Encabezados faltantes en el archivo.',
                    'missing' => $required,
                ], 422);
            }
        }

        $created = 0;
        $updated = 0;
        $errors = [];
        $imported = [];
        $line = 1;

        while (($row = fgetcsv($handle, 0, ',')) !== false) {
            $line++;

            if ($this->rowIsEmpty($row)) {
                continue;
            }

            $data = $this->mapRow($headers, $row);

            if (empty($data['clock_name']) || empty($data['serial_number'])) {
                $errors[] = "Línea {$line}: faltan nombre o serie.";
                continue;
            }

            try {
                DB::beginTransaction();

                $payload = [
                    'clock_name' => $data['clock_name'],
                    'serial_number' => $data['serial_number'],
                    'ip_address' => $data['ip_address'] ?? null,
                    'firmware_version' => $data['firmware_version'] ?? null,
                    'type_inout' => $data['type_inout'] ?? null,
                    'status' => $this->mapStatus($data['status'] ?? '1'),
                    'monitoring_status' => $this->mapMonitoringStatus($data['monitoring_status'] ?? null),
                    'program_status' => $data['program_status'] ?? null,
                ];

                if (! empty($data['company_code'])) {
                    $company = Company::where('code', $data['company_code'])
                        ->orWhere('name', $data['company_code'])
                        ->first();
                    $payload['company_id'] = $company?->id;
                }

                if (! empty($data['unit_code'])) {
                    $location = Location::where('code', $data['unit_code'])
                        ->orWhere('name', $data['unit_code'])
                        ->first();
                    $payload['location_id'] = $location?->id;
                }

                if (! empty($data['last_heartbeat_at'])) {
                    $payload['last_heartbeat_at'] = $data['last_heartbeat_at'];
                }

                if (! empty($data['status_message'])) {
                    $payload['last_status_message'] = $data['status_message'];
                }

                $clock = Clock::updateOrCreate(
                    ['serial_number' => $data['serial_number']],
                    array_filter($payload, fn ($value) => $value !== null)
                );

                $clock->wasRecentlyCreated ? $created++ : $updated++;

                $imported[] = ClockResource::make($clock->fresh(['company', 'location']))->resolve();

                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                $errors[] = "Línea {$line}: {$e->getMessage()}";
            }
        }

        fclose($handle);

        return response()->json([
            'message' => 'Importación finalizada',
            'summary' => [
                'created' => $created,
                'updated' => $updated,
                'errors' => $errors,
            ],
            'data' => $imported,
        ]);
    }

    protected function rowIsEmpty(array $row): bool
    {
        return collect($row)
            ->filter(fn ($value) => trim((string) $value) !== '')
            ->isEmpty();
    }

    protected function mapRow(array $headers, array $row): array
    {
        $values = array_map(fn ($value) => trim((string) $value), array_pad($row, count($headers), null));

        return array_combine($headers, $values) ?: [];
    }

    protected function mapStatus(string $value): int
    {
        $normalized = strtolower($value);

        if (in_array($normalized, ['0', 'false', 'inactivo', 'off'], true)) {
            return 0;
        }

        return 1;
    }

    protected function mapMonitoringStatus(?string $value): string
    {
        $normalized = strtolower($value ?? '');

        return in_array($normalized, ['online', 'warning', 'offline'], true)
            ? $normalized
            : 'offline';
    }
}
