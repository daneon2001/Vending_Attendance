<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class PilotPreflightCommand extends Command
{
    protected $signature = 'vending:pilot-preflight';

    protected $description = 'Validate non-secret deployment gates for a controlled vending pilot';

    public function handle(): int
    {
        $environment = app()->environment();
        $appUrl = (string) config('app.url');
        $database = (string) config('database.connections.'.config('database.default').'.database');
        $queue = (string) config('queue.default');
        $cache = (string) config('cache.default');
        $mobileApiUrl = (string) config('vending.pilot.mobile_api_url');
        $checks = [
            ['APP_ENV', in_array($environment, ['pilot', 'production'], true), $environment],
            ['APP_DEBUG', config('app.debug') === false, config('app.debug') ? 'enabled' : 'disabled'],
            ['APP_URL', Str::startsWith($appUrl, 'https://'), $this->safeOrigin($appUrl)],
            ['DB_CONNECTION', in_array(config('database.default'), ['mysql', 'mariadb'], true), (string) config('database.default')],
            ['DB_DATABASE', $this->isPilotDatabase($database), $database],
            ['QUEUE_CONNECTION', in_array($queue, ['database', 'redis'], true), $queue],
            ['CACHE_STORE', in_array($cache, ['database', 'redis'], true), $cache],
            ['SCHEDULER_SUPERVISED', config('vending.pilot.scheduler_supervised') === true, config('vending.pilot.scheduler_supervised') ? 'declared' : 'missing'],
            ['MOBILE_API_URL', Str::startsWith($mobileApiUrl, 'https://'), $this->safeOrigin($mobileApiUrl)],
            ['SESSION_SECURE_COOKIE', config('session.secure') === true, config('session.secure') ? 'enabled' : 'disabled'],
            ['LOG_LEVEL', in_array(config('logging.channels.single.level'), ['info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'], true), (string) config('logging.channels.single.level')],
        ];

        $this->table(
            ['Check', 'Result', 'Non-secret value'],
            array_map(fn (array $check): array => [$check[0], $check[1] ? 'PASS' : 'FAIL', $check[2]], $checks),
        );

        if (collect($checks)->contains(fn (array $check): bool => $check[1] === false)) {
            $this->error('Pilot preflight failed. No secret values were printed.');

            return self::FAILURE;
        }

        $this->info('Pilot preflight passed. External TLS, scheduler, backup and signed APK evidence remain operator gates.');

        return self::SUCCESS;
    }

    private function isPilotDatabase(string $database): bool
    {
        return Str::startsWith($database, 'vending_attendance_')
            && ! preg_match('/(?:dev|test|testing|restore)/i', $database);
    }

    private function safeOrigin(string $url): string
    {
        if ($url === '') {
            return 'missing';
        }

        $parts = parse_url($url);

        return isset($parts['scheme'], $parts['host'])
            ? $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '')
            : 'invalid';
    }
}
