<?php

namespace Tests\Feature\Security;

use App\Models\AttendanceRecord;
use App\Services\Attendance\AttendanceIntegrityService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AttendanceIntegrityVerificationTest extends TestCase
{
    private bool $createdAttendanceLogs = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('attendance_logs')) {
            Schema::create('attendance_logs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('log_id')->nullable();
                $table->unsignedBigInteger('employee_id')->nullable();
                $table->unsignedBigInteger('device_id')->nullable();
                $table->dateTime('log_date')->nullable();
                $table->integer('log_type')->default(0);
                $table->string('source', 20)->nullable();
                $table->json('raw_payload')->nullable();
                $table->string('integrity_hash', 64)->nullable();
                $table->string('integrity_previous_hash', 64)->nullable();
                $table->unsignedSmallInteger('integrity_hash_version')->default(1);
                $table->dateTime('integrity_verified_at')->nullable();
                $table->timestamps();
            });
            $this->createdAttendanceLogs = true;
        }

        DB::table('attendance_logs')->delete();
    }

    protected function tearDown(): void
    {
        if ($this->createdAttendanceLogs && Schema::hasTable('attendance_logs')) {
            Schema::drop('attendance_logs');
        }

        parent::tearDown();
    }

    public function test_verify_integrity_detects_tampering_in_hashed_row(): void
    {
        AttendanceRecord::query()->create([
            'log_id' => 1001,
            'employee_id' => 1,
            'device_id' => 10,
            'log_date' => now()->subMinutes(5),
            'log_type' => 1,
            'source' => 'api',
            'raw_payload' => ['provider' => 'onprem', 'type' => 'IN'],
        ]);

        AttendanceRecord::query()->create([
            'log_id' => 1002,
            'employee_id' => 1,
            'device_id' => 10,
            'log_date' => now()->subMinutes(1),
            'log_type' => 2,
            'source' => 'api',
            'raw_payload' => ['provider' => 'onprem', 'type' => 'OUT'],
        ]);

        $secondId = (int) AttendanceRecord::query()->orderByDesc('id')->value('id');

        DB::table('attendance_logs')
            ->where('id', $secondId)
            ->update(['log_date' => now()->addMinute()]);

        $result = app(AttendanceIntegrityService::class)->verifyIntegrity();

        $this->assertFalse((bool) $result['ok']);
        $this->assertSame(1, (int) $result['compromised_count']);
        $this->assertSame($secondId, (int) $result['compromised'][0]['attendance_id']);
        $this->assertContains('ROW_HASH_MISMATCH', (array) $result['compromised'][0]['issues']);
    }
}
