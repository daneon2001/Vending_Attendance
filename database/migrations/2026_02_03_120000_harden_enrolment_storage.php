<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_fingerprints')) {
            Schema::create('employee_fingerprints', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
                $table->foreignId('clock_id')->nullable()->constrained('clocks')->nullOnDelete();
                $table->string('vendor_template_id', 191)->nullable();
                $table->longText('template_b64')->nullable();
                $table->string('template_format', 40)->nullable();
                $table->string('enrolment_type', 50)->nullable();
                $table->string('device_serial', 191)->nullable();
                $table->string('status', 30)->default('enrolled');
                $table->dateTime('enrolled_at')->nullable();
                $table->dateTime('performed_at')->nullable();
                $table->dateTime('deleted_at')->nullable();
                $table->timestamps();

                $table->index('vendor_template_id', 'employee_fingerprints_vendor_template_id_idx');
                $table->unique(
                    ['employee_id', 'vendor_template_id'],
                    'employee_fingerprints_employee_vendor_unique'
                );
            });
        } else {
            Schema::table('employee_fingerprints', function (Blueprint $table) {
                if (! Schema::hasColumn('employee_fingerprints', 'template_b64')) {
                    $table->longText('template_b64')->nullable()->after('vendor_template_id');
                }

                if (! Schema::hasColumn('employee_fingerprints', 'template_format')) {
                    $table->string('template_format', 40)->nullable()->after('template_b64');
                }

                if (! Schema::hasColumn('employee_fingerprints', 'enrolment_type')) {
                    $table->string('enrolment_type', 50)->nullable()->after('template_format');
                }

                if (! Schema::hasColumn('employee_fingerprints', 'device_serial')) {
                    $table->string('device_serial', 191)->nullable()->after('enrolment_type');
                }

                if (! Schema::hasColumn('employee_fingerprints', 'performed_at')) {
                    $table->dateTime('performed_at')->nullable()->after('enrolled_at');
                }

                $table->index('vendor_template_id', 'employee_fingerprints_vendor_template_id_idx');
                $table->unique(
                    ['employee_id', 'vendor_template_id'],
                    'employee_fingerprints_employee_vendor_unique'
                );
            });
        }

        if (! Schema::hasTable('enrolment_audits')) {
            Schema::create('enrolment_audits', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('employee_id')->nullable();
                $table->unsignedBigInteger('clock_id')->nullable();
                $table->string('enrolment_type', 50)->nullable();
                $table->string('vendor_template_id', 191)->nullable();
                $table->string('device_serial', 191)->nullable();
                $table->dateTime('performed_at')->nullable();
                $table->string('status', 20);
                $table->string('reason', 255)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index('employee_id');
                $table->index('clock_id');
                $table->index('status');
                $table->index('vendor_template_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('enrolment_audits');

        if (! Schema::hasTable('employee_fingerprints')) {
            return;
        }

        Schema::table('employee_fingerprints', function (Blueprint $table) {
            $table->dropUnique('employee_fingerprints_employee_vendor_unique');
            $table->dropIndex('employee_fingerprints_vendor_template_id_idx');
            if (Schema::hasColumn('employee_fingerprints', 'performed_at')) {
                $table->dropColumn('performed_at');
            }

            if (Schema::hasColumn('employee_fingerprints', 'device_serial')) {
                $table->dropColumn('device_serial');
            }

            if (Schema::hasColumn('employee_fingerprints', 'enrolment_type')) {
                $table->dropColumn('enrolment_type');
            }

            if (Schema::hasColumn('employee_fingerprints', 'template_format')) {
                $table->dropColumn('template_format');
            }

            if (Schema::hasColumn('employee_fingerprints', 'template_b64')) {
                $table->dropColumn('template_b64');
            }
        });
    }
};
