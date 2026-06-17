<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class CorporateRecruitmentAttendanceWorkbookExport implements WithMultipleSheets
{
    /**
     * @param  array<string, mixed>  $exportData
     */
    public function __construct(
        protected array $exportData
    ) {
    }

    public function sheets(): array
    {
        $sheets = [
            new CorporateRecruitmentAttendanceSheetExport(
                'Reporte checadas',
                $this->exportData['sheets']['report'] ?? []
            ),
            new CorporateRecruitmentAttendanceSheetExport(
                'Resumen',
                $this->exportData['sheets']['summary'] ?? []
            ),
        ];

        if (! empty($this->exportData['sheets']['raw'] ?? [])) {
            $sheets[] = new CorporateRecruitmentAttendanceSheetExport(
                'Detalle crudo',
                $this->exportData['sheets']['raw']
            );
        }

        return $sheets;
    }
}
