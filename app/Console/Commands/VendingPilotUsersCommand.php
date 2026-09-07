<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Throwable;

class VendingPilotUsersCommand extends Command
{
    protected $signature = 'vending:pilot-users {--reset-passwords : Reset only the four existing pilot accounts from their environment variables}';

    protected $description = 'Explicit local/testing pilot password reset; never changes roles or permissions.';

    private const USERS = [
        ['email' => 'pilot.admin@example.test', 'role' => 'Vending Pilot Admin', 'variable' => 'VENDING_PILOT_ADMIN_PASSWORD'],
        ['email' => 'pilot.operator@example.test', 'role' => 'Vending Pilot Operator', 'variable' => 'VENDING_PILOT_OPERATOR_PASSWORD'],
        ['email' => 'pilot.support@example.test', 'role' => 'Vending Pilot Support', 'variable' => 'VENDING_PILOT_SUPPORT_PASSWORD'],
        ['email' => 'pilot.viewer@example.test', 'role' => 'Vending Pilot Viewer', 'variable' => 'VENDING_PILOT_VIEWER_PASSWORD'],
    ];

    public function handle(): int
    {
        if (! $this->option('reset-passwords')) {
            return self::INVALID;
        }
        // No seeder override or Artisan --env option may bypass a production environment.
        if (! $this->laravel->environment(['local', 'testing'])
            || ! in_array(Env::get('APP_ENV'), ['local', 'testing'], true)
            || ! in_array(config('app.env'), ['local', 'testing'], true)) {
            return self::FAILURE;
        }

        try {
            $passwords = [];
            foreach (self::USERS as $definition) {
                // Read current environment values, never CLI input or stale config-cache passwords.
                $password = Env::get($definition['variable']);
                if (! is_string($password) || trim($password) === '' || strlen($password) < 12) {
                    return self::FAILURE;
                }
                $passwords[$definition['email']] = $password;
            }

            DB::transaction(function () use ($passwords): void {
                $users = User::query()->whereIn('email', array_column(self::USERS, 'email'))
                    ->with('roles:id,name')->orderBy('id')->lockForUpdate()
                    ->get(['id', 'email'])->keyBy('email');

                // Validate all fixed identities and existing roles before the first password write.
                foreach (self::USERS as $definition) {
                    $user = $users->get($definition['email']);
                    if (! $user || $user->email !== $definition['email']
                        || ! $user->roles->contains('name', $definition['role'])) {
                        throw new LogicException('Pilot account prerequisites failed.');
                    }
                }

                foreach (self::USERS as $definition) {
                    $updated = DB::table('users')
                        ->where('id', $users->get($definition['email'])->id)
                        ->where('email', $definition['email'])
                        ->update(['password' => Hash::make($passwords[$definition['email']])]);
                    if ($updated !== 1) {
                        throw new LogicException('Pilot password update failed.');
                    }
                }
            });
        } catch (Throwable) {
            // A database/hasher exception can contain bindings or credentials.
            // Do not print, log, report, or rethrow it. The transaction already rolled back.
            return self::FAILURE;
        }

        // No output until all four writes have committed. No seeder, events, audit or RBAC writes.
        foreach (self::USERS as $definition) {
            $this->line($definition['email']."\t".$definition['role']."\tPASSWORD_RESET");
        }

        return self::SUCCESS;
    }
}
