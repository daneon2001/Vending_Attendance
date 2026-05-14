<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeFaceTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'fortia_employee_id',
        'employee_code',
        'template_hash',
        'embedding_encrypted',
        'quality_score',
        'model_name',
        'model_version',
        'source_device',
        'source_serial',
        'captured_at',
        'synced_at',
        'is_active',
    ];

    protected $casts = [
        'quality_score' => 'float',
        'captured_at' => 'datetime',
        'synced_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
