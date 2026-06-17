<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class CorporateRecruitmentDashboardExport implements WithMultipleSheets
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
            new ArraySheetExport('Resumen global', $this->exportData['sheets']['global'] ?? []),
            new ArraySheetExport('Por unidad', $this->exportData['sheets']['locations'] ?? []),
            new ArraySheetExport('Ranking relojes', $this->exportData['sheets']['ranking'] ?? []),
        ];

        if (! empty($this->exportData['sheets']['detail'] ?? [])) {
            $sheets[] = new ArraySheetExport('Detalle checadas', $this->exportData['sheets']['detail']);
        }

        return $sheets;
    }
}
