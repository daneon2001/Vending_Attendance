<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeScopeDeletion extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'scope_location_id',
        'deleted_at',
        'reason',
    ];

    protected $casts = [
        'scope_location_id' => 'integer',
        'deleted_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
