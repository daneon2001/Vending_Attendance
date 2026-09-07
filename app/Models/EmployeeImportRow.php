<?php

namespace App\Models;

use App\Enums\Employees\EmployeeImportRowClassification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeImportRow extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_import_run_id', 'employee_id', 'row_number', 'employee_number',
        'full_name', 'normalized_status', 'classification', 'errors', 'changes',
    ];

    protected function casts(): array
    {
        return [
            'classification' => EmployeeImportRowClassification::class,
            'errors' => 'array',
            'changes' => 'array',
            'row_number' => 'integer',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(EmployeeImportRun::class, 'employee_import_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
