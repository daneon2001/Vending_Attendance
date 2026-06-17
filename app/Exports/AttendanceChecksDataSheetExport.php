<?php

namespace App\Exports;

use App\Models\AttendanceRecord;
use App\Support\AttendanceChecksExportFormatter;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

class AttendanceChecksDataSheetExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithTitle
{
    public function __construct(
        protected Builder $query,
        protected array $columns,
        protected AttendanceChecksExportFormatter $formatter,
        protected string $title = 'Checadas'
    ) {
    }

    public function title(): string
    {
        return $this->title;
    }

    public function query(): Builder
    {
        return clone $this->query;
    }

    public function headings(): array
    {
        return $this->formatter->headings($this->columns, true);
    }

    /**
     * @param  AttendanceRecord  $record
     * @return array<int, mixed>
     */
    public function map($record): array
    {
        return $this->formatter->exportValuesForRecord($record, $this->columns);
    }
}
