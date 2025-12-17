<?php

namespace App\Console\Commands;

use App\Models\FortiaMockEmployee;
use Carbon\Carbon;
use Illuminate\Console\Command;

class FortiaMockAddEmployee extends Command
{
    protected $signature = 'fortia-mock:add-employee
        {--company_id= : Company identifier}
        {--company_name= : Company name (default Medical Life Demo)}
        {--employee_id= : Employee identifier}
        {--name= : First name}
        {--last_name= : Last name}
        {--second_last_name= : Second last name}
        {--status=A : Status value}
        {--base_location_id= : Base location id}
        {--base_location_name= : Base location name}
        {--department_id= : Department id}
        {--department_name= : Department name}
        {--email_company= : Corporate email}';

    protected $description = 'Inserta o actualiza un empleado en la base mock de Fortia';

    public function handle(): int
    {
        $companyId = (int) $this->option('company_id');
        $employeeId = (int) $this->option('employee_id');

        if (! $companyId || ! $employeeId) {
            $this->error('company_id y employee_id son obligatorios.');
            return self::FAILURE;
        }

        $payload = [
            'company_id' => $companyId,
            'company_name' => $this->option('company_name') ?? 'Medical Life Demo',
            'employee_id' => $employeeId,
            'name' => $this->option('name') ?? 'John',
            'last_name' => $this->option('last_name') ?? 'Doe',
            'second_last_name' => $this->option('second_last_name'),
            'status' => $this->option('status') ?? 'A',
            'base_location_id' => (int) ($this->option('base_location_id') ?? 1),
            'base_location_name' => $this->option('base_location_name') ?? 'Default Location',
            'department_id' => (int) ($this->option('department_id') ?? 1),
            'department_name' => $this->option('department_name') ?? 'Operaciones',
            'email_company' => $this->option('email_company') ?? 'demo@medical.test',
            'updated_at' => Carbon::now(),
        ];

        $existing = FortiaMockEmployee::on('fortia_mock')
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->first();

        if ($existing) {
            $existing->fill($payload)->save();
            $this->info("Empleado {$employeeId} actualizado.");
        } else {
            $payload['created_at'] = $payload['updated_at'];
            FortiaMockEmployee::on('fortia_mock')->create($payload);
            $this->info("Empleado {$employeeId} creado.");
        }

        return self::SUCCESS;
    }
}
