<?php

namespace Tests\Feature\Security;

use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class AttendanceAuditTrailTest extends TestCase
{
    private bool $createdAttendanceLogsTable = false;

    private bool $createdAuditLogsTable = false;

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
                $table->tinyInteger('log_type')->default(0);
                $table->string('source', 20)->nullable();
                $table->string('attendance_status', 20)->nullable();
                $table->string('adjustment_reason', 500)->nullable();
                $table->json('raw_payload')->nullable();
                $table->string('integrity_hash', 64)->nullable();
                $table->string('integrity_previous_hash', 64)->nullable();
                $table->unsignedSmallInteger('integrity_hash_version')->default(1);
                $table->dateTime('integrity_verified_at')->nullable();
                $table->timestamps();
            });
            $this->createdAttendanceLogsTable = true;
        }

        if (! Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->string('actor_type', 40)->nullable();
                $table->string('actor_identifier', 191)->nullable();
                $table->string('user_name')->nullable();
                $table->string('user_email')->nullable();
                $table->string('event');
                $table->string('action', 40)->nullable();
                $table->string('entity', 120)->nullable();
                $table->string('entity_id', 120)->nullable();
                $table->string('auditable_type')->nullable();
                $table->unsignedBigInteger('auditable_id')->nullable();
                $table->text('description')->nullable();
                $table->string('reason', 500)->nullable();
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->json('metadata')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->string('request_id', 64)->nullable();
                $table->string('correlation_id', 64)->nullable();
                $table->unsignedBigInteger('device_id')->nullable();
                $table->dateTime('occurred_at_utc')->nullable();
                $table->dateTime('occurred_at_local')->nullable();
                $table->string('timezone', 64)->nullable();
                $table->timestamps();
            });
            $this->createdAuditLogsTable = true;
        }

        DB::table('attendance_logs')->delete();
        DB::table('audit_logs')->delete();
    }

    protected function tearDown(): void
    {
        if ($this->createdAuditLogsTable && Schema::hasTable('audit_logs')) {
            Schema::drop('audit_logs');
        }

        if ($this->createdAttendanceLogsTable && Schema::hasTable('attendance_logs')) {
            Schema::drop('attendance_logs');
        }

        parent::tearDown();
    }

    public function test_updating_attendance_record_generates_before_after_audit_log(): void
    {
        $record = AttendanceRecord::query()->create([
            'log_id' => 7001,
            'employee_id' => 12,
            'device_id' => 5,
            'log_date' => now()->subMinutes(10),
            'log_type' => 1,
            'source' => 'api',
            'attendance_status' => 'valida',
            'raw_payload' => ['source' => 'onprem'],
        ]);

        $record->update([
            'attendance_status' => 'corregida',
            'adjustment_reason' => 'Correccion justificada',
        ]);

        $audit = AuditLog::query()
            ->where('event', 'attendance.record.updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame('update', $audit->action);
        $this->assertSame('attendance_logs', $audit->entity);
        $this->assertSame((string) $record->id, $audit->entity_id);
        $this->assertIsArray($audit->old_values);
        $this->assertIsArray($audit->new_values);
        $this->assertNotEmpty($audit->old_values);
        $this->assertNotEmpty($audit->new_values);
        $this->assertTrue(
            array_key_exists('attendance_status', $audit->new_values)
            || array_key_exists('adjustment_reason', $audit->new_values)
        );

        if (array_key_exists('attendance_status', $audit->new_values)) {
            $this->assertSame('corregida', $audit->new_values['attendance_status']);
        }

        if (array_key_exists('adjustment_reason', $audit->new_values)) {
            $this->assertSame('Correccion justificada', $audit->new_values['adjustment_reason']);
        }
    }

    public function test_audit_logs_are_append_only_for_updates_by_default(): void
    {
        $log = AuditLog::query()->create([
            'event' => 'security.test',
            'description' => 'Append only update test',
            'action' => 'event',
            'entity' => 'audit_logs',
        ]);

        $this->expectException(RuntimeException::class);
        $log->update(['description' => 'mutated']);
    }

    public function test_audit_logs_are_append_only_for_deletes_by_default(): void
    {
        $log = AuditLog::query()->create([
            'event' => 'security.test',
            'description' => 'Append only delete test',
            'action' => 'event',
            'entity' => 'audit_logs',
        ]);

        $this->expectException(RuntimeException::class);
        $log->delete();
    }
}
