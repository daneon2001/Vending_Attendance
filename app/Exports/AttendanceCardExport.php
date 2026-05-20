<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;
use Maatwebsite\Excel\Concerns\WithDrawings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class AttendanceCardExport implements FromArray, ShouldAutoSize, WithCustomStartCell, WithDrawings, WithEvents, WithHeadings, WithStyles
{
    public function __construct(
        private readonly array $payload,
        private readonly ?string $logoPath = null,
    ) {
    }

    public function array(): array
    {
        return Collection::make(data_get($this->payload, 'card.rows', []))
            ->map(fn (array $row) => [
                $row['date_display'] ?? '',
                $row['day'] ?? '',
                $row['entry_display'] ?? '',
                $row['exit_display'] ?? '',
                $row['worked_hours_display'] ?? '',
                $row['late_display'] ?? '',
                $row['method_label'] ?? '',
                $row['status_label'] ?? '',
                $row['observations'] ?? '',
            ])
            ->all();
    }

    public function headings(): array
    {
        return [
            'Fecha',
            'Dia',
            'Entrada',
            'Salida',
            'Horas',
            'Retardo',
            'Metodo',
            'Estatus',
            'Observaciones',
        ];
    }

    public function startCell(): string
    {
        return 'A7';
    }

    public function drawings(): array
    {
        if (! $this->logoPath || ! is_file($this->logoPath)) {
            return [];
        }

        $drawing = new Drawing();
        $drawing->setName('Medical Life');
        $drawing->setDescription('Medical Life');
        $drawing->setPath($this->logoPath);
        $drawing->setHeight(52);
        $drawing->setCoordinates('A1');
        $drawing->setOffsetX(6);
        $drawing->setOffsetY(6);

        return [$drawing];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            7 => [
                'font' => ['bold' => true, 'color' => ['rgb' => '0F172A']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'E2E8F0'],
                ],
                'alignment' => [
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $employee = data_get($this->payload, 'card.employee', []);
                $summary = data_get($this->payload, 'card.summary', []);
                $period = data_get($this->payload, 'period', []);
                $timezone = data_get($this->payload, 'timezone', []);
                $rowCount = count(data_get($this->payload, 'card.rows', []));
                $tableEndRow = max(7 + $rowCount, 7);

                $sheet->mergeCells('B1:I1');
                $sheet->setCellValue('B1', 'Tarjeta de Asistencia');
                $sheet->setCellValue('B2', 'Empleado: '.((string) ($employee['name'] ?? 'Sin empleado')));
                $sheet->setCellValue('B3', 'Empresa: '.((string) ($employee['company'] ?? 'Sin empresa')));
                $sheet->setCellValue('E2', 'Sucursal: '.((string) ($employee['location'] ?? 'Sin unidad')));
                $sheet->setCellValue('E3', 'Periodo: '.((string) ($period['display'] ?? '')));
                $sheet->setCellValue('H2', 'Departamento: '.((string) ($employee['department'] ?? 'Sin departamento')));
                $sheet->setCellValue('H3', 'Zona horaria: '.((string) ($timezone['label'] ?? '')));

                $sheet->setCellValue('A5', 'Dias asistidos');
                $sheet->setCellValue('B5', (int) ($summary['days_attended'] ?? 0));
                $sheet->setCellValue('C5', 'Faltas');
                $sheet->setCellValue('D5', (int) ($summary['absences'] ?? 0));
                $sheet->setCellValue('E5', 'Retardos');
                $sheet->setCellValue('F5', (int) ($summary['late_days'] ?? 0));
                $sheet->setCellValue('G5', 'Horas trabajadas');
                $sheet->setCellValue('H5', (string) ($summary['worked_hours'] ?? '0h 00m'));
                $sheet->setCellValue('I5', 'Cobertura');
                $sheet->setCellValue('J5', (string) ($summary['coverage_label'] ?? '0.0%'));

                $sheet->getStyle('B1')->applyFromArray([
                    'font' => [
                        'bold' => true,
                        'size' => 16,
                        'color' => ['rgb' => '0F172A'],
                    ],
                ]);

                $sheet->getStyle('A5:J5')->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'F8FAFC'],
                    ],
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['rgb' => 'CBD5E1'],
                        ],
                    ],
                ]);

                $sheet->getStyle("A7:I{$tableEndRow}")->applyFromArray([
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['rgb' => 'E2E8F0'],
                        ],
                    ],
                    'alignment' => [
                        'vertical' => Alignment::VERTICAL_CENTER,
                    ],
                ]);

                $sheet->freezePane('A8');
            },
        ];
    }
}
