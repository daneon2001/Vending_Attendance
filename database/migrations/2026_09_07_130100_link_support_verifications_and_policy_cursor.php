<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $t) {
            $t->char('creation_fingerprint', 64)->nullable();
            $t->timestamp('response_warning_at')->nullable();
            $t->timestamp('resolution_warning_at')->nullable();
            $t->index(['response_warned', 'response_warning_at'], 'support_response_warning');
            $t->index(['resolution_warned', 'resolution_warning_at'], 'support_resolution_warning');
        });
        Schema::table('support_verifications', function (Blueprint $t) {
            $t->foreignId('ticket_id')->nullable()->constrained('support_tickets')->restrictOnDelete();
        });
        DB::table('support_runtime_cursors')->insert(['key' => 'policy_version', 'value' => 0]);
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $t) {
            $t->dropIndex('support_response_warning');
            $t->dropIndex('support_resolution_warning');
            $t->dropColumn(['creation_fingerprint', 'response_warning_at', 'resolution_warning_at']);
        });
        Schema::table('support_verifications', function (Blueprint $t) {
            $t->dropConstrainedForeignId('ticket_id');
        });
        DB::table('support_runtime_cursors')->where('key', 'policy_version')->delete();
    }
};
