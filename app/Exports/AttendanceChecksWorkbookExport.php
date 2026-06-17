<?php

namespace App\Exports;

use App\Support\AttendanceChecksExportFormatter;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class AttendanceChecksWorkbookExport implements WithMultipleSheets
{
    /**
     * @param  array<int, array<int, mixed>>  $summaryRows
     * @param  array<int, string>  $columns
     */
    public function __construct(
        protected array $summaryRows,
        protected array $columns,
        protected AttendanceChecksExportFormatter $formatter,
        protected ?Builder $query = null,
        protected ?array $detailRows = null
    ) {
    }

    public function sheets(): array
    {
        $sheets = [
            new ArraySheetExport('Resumen', $this->summaryRows),
        ];

        if ($this->query !== null) {
            $sheets[] = new AttendanceChecksDataSheetExport(
                query: $this->query,
                columns: $this->columns,
                formatter: $this->formatter,
                title: 'Checadas'
            );

            return $sheets;
        }

        $sheets[] = new ArraySheetExport('Checadas', $this->detailRows ?? []);

        return $sheets;
    }
}
