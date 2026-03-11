<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employees')) {
            return;
        }

        Schema::table('employees', function (Blueprint $table): void {
            if (Schema::hasColumn('employees', 'base_location_id') && ! Schema::hasIndex('employees', 'employees_base_location_id_index')) {
                $table->index('base_location_id');
            }

            if (Schema::hasColumn('employees', 'status') && ! Schema::hasIndex('employees', 'employees_status_index')) {
                $table->index('status');
            }

            if (Schema::hasColumn('employees', 'full_name') && ! Schema::hasIndex('employees', 'employees_full_name_index')) {
                $table->index('full_name');
            }

            if (Schema::hasColumn('employees', 'updated_at') && ! Schema::hasIndex('employees', 'employees_updated_at_index')) {
                $table->index('updated_at');
            }

            if (
                Schema::hasColumn('employees', 'status')
                && Schema::hasColumn('employees', 'base_location_id')
                && Schema::hasColumn('employees', 'updated_at')
                && ! Schema::hasIndex('employees', 'employees_status_base_location_updated_idx')
            ) {
                $table->index(
                    ['status', 'base_location_id', 'updated_at'],
                    'employees_status_base_location_updated_idx'
                );
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('employees')) {
            return;
        }

        Schema::table('employees', function (Blueprint $table): void {
            if (Schema::hasIndex('employees', 'employees_status_base_location_updated_idx')) {
                $table->dropIndex('employees_status_base_location_updated_idx');
            }

            if (Schema::hasIndex('employees', 'employees_updated_at_index')) {
                $table->dropIndex('employees_updated_at_index');
            }

            if (Schema::hasIndex('employees', 'employees_full_name_index')) {
                $table->dropIndex('employees_full_name_index');
            }

            if (Schema::hasIndex('employees', 'employees_status_index')) {
                $table->dropIndex('employees_status_index');
            }

            if (Schema::hasIndex('employees', 'employees_base_location_id_index')) {
                $table->dropIndex('employees_base_location_id_index');
            }
        });
    }
};
