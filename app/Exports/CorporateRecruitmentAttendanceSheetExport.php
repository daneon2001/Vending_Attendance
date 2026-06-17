<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class CorporateRecruitmentAttendanceSheetExport implements FromArray, ShouldAutoSize, WithEvents, WithTitle
{
    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    public function __construct(
        protected string $title,
        protected array $rows,
        protected int $headingRow = 1
    ) {
    }

    public function title(): string
    {
        return $this->title;
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                if ($this->rows === []) {
                    return;
                }

                $sheet = $event->sheet->getDelegate();
                $maxColumns = max(array_map(fn (array $row) => count($row), $this->rows));
                $lastColumn = Coordinate::stringFromColumnIndex(max($maxColumns, 1));
                $lastRow = count($this->rows);
                $headingRange = "A{$this->headingRow}:{$lastColumn}{$this->headingRow}";
                $bodyRange = "A{$this->headingRow}:{$lastColumn}{$lastRow}";

                $sheet->getStyle($headingRange)->applyFromArray([
                    'font' => [
                        'bold' => true,
                        'color' => ['rgb' => '0F172A'],
                    ],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'E2E8F0'],
                    ],
                    'alignment' => [
                        'vertical' => Alignment::VERTICAL_CENTER,
                    ],
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['rgb' => 'CBD5E1'],
                        ],
                    ],
                ]);

                $sheet->getStyle($bodyRange)->applyFromArray([
                    'alignment' => [
                        'vertical' => Alignment::VERTICAL_CENTER,
                    ],
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['rgb' => 'E2E8F0'],
                        ],
                    ],
                ]);

                $sheet->setAutoFilter($headingRange);
                $sheet->freezePane('A'.($this->headingRow + 1));
            },
        ];
    }
}
