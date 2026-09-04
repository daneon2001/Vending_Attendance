<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('devices')
            ->whereNull('uuid')
            ->orderBy('id')
            ->eachById(function (object $device): void {
                DB::table('devices')->where('id', $device->id)->update([
                    'uuid' => (string) Str::uuid(),
                ]);
            });

        Schema::table('devices', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable()->change();
        });
    }
};
