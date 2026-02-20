<?php

namespace App\Console\Commands;

use App\Services\Attendance\AttendanceIntegrityService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class VerifyAttendanceIntegrity extends Command
{
    protected $signature = 'attendance:verify-integrity
        {--from= : Start date/time (e.g. 2026-02-01 or 2026-02-01 00:00:00)}
        {--to= : End date/time (e.g. 2026-02-29 or 2026-02-29 23:59:59)}
        {--json : Print JSON output}';

    protected $description = 'Verify tamper-evident integrity hash chain for attendance records.';

    public function handle(AttendanceIntegrityService $service): int
    {
        $from = $this->option('from') ? Carbon::parse((string) $this->option('from'))->startOfDay() : null;
        $to = $this->option('to') ? Carbon::parse((string) $this->option('to'))->endOfDay() : null;

        $result = $service->verifyIntegrity($from, $to);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info('Attendance integrity verification completed.');
            $this->line('checked: '.$result['total_checked']);
            $this->line('compromised: '.$result['compromised_count']);

            if (! empty($result['compromised'])) {
                $this->table(
                    ['attendance_id', 'employee_id', 'log_date', 'issues'],
                    collect($result['compromised'])->map(fn ($row) => [
                        $row['attendance_id'] ?? null,
                        $row['employee_id'] ?? null,
                        $row['log_date'] ?? null,
                        implode(', ', (array) ($row['issues'] ?? [])),
                    ])->all()
                );
            }
        }

        if (! empty($result['compromised'])) {
            Log::alert('attendance.integrity.compromised', [
                'from' => optional($from)->toISOString(),
                'to' => optional($to)->toISOString(),
                'total_checked' => $result['total_checked'],
                'compromised_count' => $result['compromised_count'],
                'compromised' => $result['compromised'],
            ]);

            return self::FAILURE;
        }

        Log::info('attendance.integrity.verified', [
            'from' => optional($from)->toISOString(),
            'to' => optional($to)->toISOString(),
            'total_checked' => $result['total_checked'],
            'compromised_count' => $result['compromised_count'],
        ]);

        return self::SUCCESS;
    }
}
