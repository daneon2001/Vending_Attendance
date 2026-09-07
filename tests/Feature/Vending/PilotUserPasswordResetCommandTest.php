<?php

namespace Tests\Feature\Vending;

use App\Actions\SyncPermissionCatalog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class PilotUserPasswordResetCommandTest extends TestCase
{
    use RefreshDatabase;

    private const USERS = [
        'admin' => ['pilot.admin@example.test', 'Vending Pilot Admin', 'VENDING_PILOT_ADMIN_PASSWORD'],
        'operator' => ['pilot.operator@example.test', 'Vending Pilot Operator', 'VENDING_PILOT_OPERATOR_PASSWORD'],
        'support' => ['pilot.support@example.test', 'Vending Pilot Support', 'VENDING_PILOT_SUPPORT_PASSWORD'],
        'viewer' => ['pilot.viewer@example.test', 'Vending Pilot Viewer', 'VENDING_PILOT_VIEWER_PASSWORD'],
    ];

    private array $environmentSnapshot = [];

    private array $passwords = [];

    private array $logEvents = [];

    protected function beforeRefreshingDatabase(): void
    {
        if (! $this->app->environment('testing') || config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:') {
            throw new \LogicException('Password reset tests require testing and in-memory SQLite.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::USERS as $key => [$email, $roleName, $variable]) {
            $this->environmentSnapshot[$variable] = [
                'server_exists' => array_key_exists($variable, $_SERVER), 'server' => $_SERVER[$variable] ?? null,
                'env_exists' => array_key_exists($variable, $_ENV), 'env' => $_ENV[$variable] ?? null,
                'process' => getenv($variable),
            ];
            $this->passwords[$key] = Str::random(32);
            $this->setVariable($variable, $this->passwords[$key]);
            $role = Role::create(['name' => $roleName, 'description' => 'Existing role, not managed by this command.']);
            $role->permissions()->sync(SyncPermissionCatalog::resolveIds(['employees' => ['view']]));
            $this->createUser($email)->roles()->attach($role);
        }
        $this->createUser('admin.vending.local@example.test');
        $this->createUser('unrelated@example.test');

        Log::listen(function (MessageLogged $event): void {
            $this->logEvents[] = $event;
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->environmentSnapshot as $variable => $original) {
            if ($original['server_exists']) {
                $_SERVER[$variable] = $original['server'];
            } else {
                unset($_SERVER[$variable]);
            }
            if ($original['env_exists']) {
                $_ENV[$variable] = $original['env'];
            } else {
                unset($_ENV[$variable]);
            }
            putenv($original['process'] === false ? $variable : $variable.'='.$original['process']);
        }
        parent::tearDown();
    }

    public function test_reset_passwords_succeeds_in_local_with_exact_output_and_no_logs_or_audit(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $before = $this->snapshot('users');
        $auditCount = DB::table('audit_logs')->count();
        [$exit, $output] = $this->runReset();

        $this->assertSame(0, $exit);
        $expected = implode("\n", array_map(fn ($row) => $row[0]."\t".$row[1]."\tPASSWORD_RESET", self::USERS))."\n";
        $this->assertTrue($expected === str_replace("\r\n", "\n", $output), 'Output must contain only email, role and PASSWORD_RESET.');
        foreach (self::USERS as $key => [$email]) {
            $row = (array) DB::table('users')->where('email', $email)->first();
            $this->assertTrue(Hash::check($this->passwords[$key], $row['password']), 'Configured password must authenticate.');
            $previous = collect($before)->firstWhere('email', $email);
            unset($row['password'], $previous['password']);
            $this->assertTrue($row === $previous, 'Only the password column may change.');
        }
        $this->assertSecretsAbsent($output, $before);
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
        $this->assertTrue($this->logEvents === [], 'Reset must not emit log events.');
    }

    public function test_reset_is_allowed_in_testing(): void
    {
        [$exit] = $this->runReset();
        $this->assertSame(0, $exit);
    }

    public function test_production_is_forbidden_even_if_seeder_override_is_enabled(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['employees.pilot.allow_production' => true]);
        $this->assertBlockedWithoutChanges();
    }

    public function test_other_environments_are_forbidden(): void
    {
        foreach (['staging', 'pilot', 'development'] as $environment) {
            $this->app->detectEnvironment(fn () => $environment);
            $this->assertBlockedWithoutChanges();
        }
    }

    public function test_application_environment_override_cannot_bypass_production_app_env(): void
    {
        $this->setVariable('APP_ENV', 'production');
        $this->app->detectEnvironment(fn () => 'local');
        $this->assertBlockedWithoutChanges();
    }

    public function test_production_configuration_cannot_be_bypassed_by_local_environment(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $this->setVariable('APP_ENV', 'local');
        config(['app.env' => 'production']);
        $this->assertBlockedWithoutChanges();
    }

    #[DataProvider('pilotKeys')]
    public function test_each_missing_variable_fails_even_with_a_stale_configured_password(string $key): void
    {
        config(['employees.pilot.users.'.$key.'.password' => Str::random(32)]);
        $this->setVariable(self::USERS[$key][2], null);
        $this->assertBlockedWithoutChanges();
    }

    public static function pilotKeys(): array
    {
        return array_combine(array_keys(self::USERS), array_map(fn ($key) => [$key], array_keys(self::USERS)));
    }

    public function test_blank_and_short_passwords_fail_before_any_write(): void
    {
        foreach (['', str_repeat(' ', 16), Str::random(8)] as $invalid) {
            $this->setVariable(self::USERS['viewer'][2], $invalid);
            $this->assertBlockedWithoutChanges();
        }
    }

    public function test_explicit_flag_is_required(): void
    {
        $before = $this->snapshot('users');
        [$exit, $output] = $this->runReset([]);
        $this->assertSame(2, $exit);
        $this->assertTrue($before === $this->snapshot('users'), 'No flag must mean no password changes.');
        $this->assertTrue($output === '', 'No implicit operation or diagnostic output is allowed.');
    }

    public function test_command_accepts_no_password_arguments_or_option_values(): void
    {
        $definition = app(\App\Console\Commands\VendingPilotUsersCommand::class)->getDefinition();
        $this->assertSame([], $definition->getArguments());
        $this->assertSame(['reset-passwords'], array_keys($definition->getOptions()));
        $this->assertFalse($definition->getOption('reset-passwords')->acceptValue());
    }

    public function test_unrelated_users_including_demo_administrator_are_untouched(): void
    {
        $before = $this->snapshot('users');
        [$exit] = $this->runReset();
        $this->assertSame(0, $exit);
        foreach (['admin.vending.local@example.test', 'unrelated@example.test'] as $email) {
            $this->assertTrue(
                collect($before)->firstWhere('email', $email) === (array) DB::table('users')->where('email', $email)->first(),
                'Non-pilot account must remain byte-for-byte unchanged.'
            );
        }
        $this->assertSame(count($before), DB::table('users')->count());
    }

    public function test_roles_permissions_and_memberships_remain_unchanged(): void
    {
        $tables = ['roles', 'permissions', 'role_user', 'permission_role'];
        $before = array_map($this->snapshot(...), $tables);
        [$exit] = $this->runReset();
        $this->assertSame(0, $exit);
        $this->assertTrue($before === array_map($this->snapshot(...), $tables), 'Reset must not provision or synchronize RBAC.');
    }

    public function test_configured_emails_cannot_redirect_the_fixed_targets(): void
    {
        foreach (array_keys(self::USERS) as $key) {
            config(['employees.pilot.users.'.$key.'.email' => 'admin.vending.local@example.test']);
        }
        $demoBefore = (array) DB::table('users')->where('email', 'admin.vending.local@example.test')->first();
        [$exit] = $this->runReset();
        $this->assertSame(0, $exit);
        $this->assertTrue($demoBefore === (array) DB::table('users')->where('email', 'admin.vending.local@example.test')->first());
    }

    public function test_missing_pilot_account_is_not_created_and_aborts_all_changes(): void
    {
        DB::table('users')->where('email', 'pilot.viewer@example.test')->update(['email' => 'former.viewer@example.test']);
        $this->assertBlockedWithoutChanges();
        $this->assertDatabaseMissing('users', ['email' => 'pilot.viewer@example.test']);
    }

    public function test_unexpected_role_aborts_without_repairing_permissions(): void
    {
        $user = User::where('email', 'pilot.viewer@example.test')->firstOrFail();
        $user->roles()->detach();
        $before = $this->snapshot('role_user');
        $this->assertBlockedWithoutChanges();
        $this->assertTrue($before === $this->snapshot('role_user'));
    }

    public function test_partial_database_failure_rolls_back_and_cannot_print_or_log_secrets(): void
    {
        $before = $this->snapshot('users');
        $secret = $this->passwords['admin'].' '.$before[0]['password'];
        $updates = 0;
        DB::listen(function (QueryExecuted $event) use (&$updates, $secret): void {
            if (str_starts_with(strtolower($event->sql), 'update "users"') && ++$updates === 2) {
                throw new \RuntimeException($secret);
            }
        });
        $auditCount = DB::table('audit_logs')->count();
        [$exit, $output] = $this->runReset();

        $this->assertSame(1, $exit);
        $this->assertSame(2, $updates);
        $this->assertTrue($output === '', 'Failures must be silent, even for exceptions containing sensitive data.');
        $this->assertTrue($before === $this->snapshot('users'), 'All password changes must roll back.');
        $this->assertTrue($this->logEvents === [], 'Exceptions must not be logged or reported.');
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
        $this->assertSecretsAbsent($output, $before);
    }

    private function createUser(string $email): User
    {
        return User::create(['name' => 'Synthetic Test User', 'email' => $email, 'password' => Str::random(32), 'estatus' => true]);
    }

    private function setVariable(string $name, ?string $value): void
    {
        if (! array_key_exists($name, $this->environmentSnapshot)) {
            $this->environmentSnapshot[$name] = [
                'server_exists' => array_key_exists($name, $_SERVER), 'server' => $_SERVER[$name] ?? null,
                'env_exists' => array_key_exists($name, $_ENV), 'env' => $_ENV[$name] ?? null,
                'process' => getenv($name),
            ];
        }
        if ($value === null) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        } else {
            $_ENV[$name] = $_SERVER[$name] = $value;
            putenv($name.'='.$value);
        }
    }

    private function snapshot(string $table): array
    {
        return DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    private function runReset(array $options = ['--reset-passwords' => true]): array
    {
        $output = new BufferedOutput;
        $exit = Artisan::call('vending:pilot-users', $options, $output);

        return [$exit, $output->fetch()];
    }

    private function assertBlockedWithoutChanges(): void
    {
        $before = $this->snapshot('users');
        [$exit, $output] = $this->runReset();
        $this->assertSame(1, $exit);
        $this->assertTrue($output === '', 'Blocked command must not print data.');
        $this->assertTrue($before === $this->snapshot('users'), 'Blocked reset must leave every account untouched.');
    }

    private function assertSecretsAbsent(string $output, array $before): void
    {
        $hashes = array_merge(array_column($before, 'password'), array_column($this->snapshot('users'), 'password'));
        foreach (array_merge($this->passwords, $hashes) as $secret) {
            $this->assertFalse(str_contains($output, $secret), 'Password values and hashes must never appear in output.');
        }
    }
}
