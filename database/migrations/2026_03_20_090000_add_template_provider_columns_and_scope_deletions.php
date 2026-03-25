<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_fingerprints')) {
            Schema::table('employee_fingerprints', function (Blueprint $table): void {
                if (! Schema::hasColumn('employee_fingerprints', 'template_vendor')) {
                    $table->string('template_vendor', 80)->nullable()->after('vendor_template_id');
                }

                if (! Schema::hasColumn('employee_fingerprints', 'template_source')) {
                    $table->string('template_source', 80)->nullable()->after('template_vendor');
                }
            });
        }

        if (Schema::hasTable('employee_template_deletions')) {
            Schema::table('employee_template_deletions', function (Blueprint $table): void {
                if (! Schema::hasColumn('employee_template_deletions', 'template_source')) {
                    $table->string('template_source', 80)->nullable()->after('vendor');
                }

                if (! Schema::hasColumn('employee_template_deletions', 'scope_location_id')) {
                    $table->unsignedBigInteger('scope_location_id')->nullable()->after('employee_id');
                    $table->index('scope_location_id', 'employee_template_deletions_scope_location_idx');
                }
            });
        }

        if (! Schema::hasTable('employee_scope_deletions')) {
            Schema::create('employee_scope_deletions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('employee_id');
                $table->unsignedBigInteger('scope_location_id');
                $table->dateTime('deleted_at');
                $table->string('reason', 80)->nullable();
                $table->timestamps();

                $table->index(['employee_id', 'scope_location_id'], 'employee_scope_deletions_employee_scope_idx');
                $table->index(['scope_location_id', 'deleted_at'], 'employee_scope_deletions_scope_deleted_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('employee_scope_deletions')) {
            Schema::drop('employee_scope_deletions');
        }

        if (Schema::hasTable('employee_template_deletions')) {
            Schema::table('employee_template_deletions', function (Blueprint $table): void {
                if (Schema::hasColumn('employee_template_deletions', 'scope_location_id')) {
                    $table->dropIndex('employee_template_deletions_scope_location_idx');
                    $table->dropColumn('scope_location_id');
                }

                if (Schema::hasColumn('employee_template_deletions', 'template_source')) {
                    $table->dropColumn('template_source');
                }
            });
        }

        if (Schema::hasTable('employee_fingerprints')) {
            Schema::table('employee_fingerprints', function (Blueprint $table): void {
                if (Schema::hasColumn('employee_fingerprints', 'template_source')) {
                    $table->dropColumn('template_source');
                }

                if (Schema::hasColumn('employee_fingerprints', 'template_vendor')) {
                    $table->dropColumn('template_vendor');
                }
            });
        }
    }
};
