<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('shift_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->integer('profile_code'); // shiftProfile
            $table->string('name');
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });

        Schema::create('shift_profile_days', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shift_profile_id');
            $table->date('work_date');          // date
            $table->time('check_in');
            $table->time('check_out');
            $table->integer('entry_range_lower_limit');
            $table->integer('entry_range_top_limit');
            $table->integer('exit_range_lower_limit');
            $table->integer('exit_range_top_limit');
            $table->timestamps();

            $table->foreign('shift_profile_id')->references('id')->on('shift_profiles')->cascadeOnDelete();
            $table->index(['shift_profile_id', 'work_date']);
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shift_profiles_tables');
    }
};
