<?php

// Read configuration only. Never opens a DB connection or prints secret values.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$url = $argv[1] ?? '';
$valid = App\Support\InternalBeta::enabled()
    && config('app.url') === $url && parse_url($url, PHP_URL_SCHEME) === 'https'
    && config('integrations.fortia_mock_migrations') === false
    && config('session.secure') === true
    && config('filesystems.disks.local.serve') === false
    && config('filesystems.disks.support_private.serve') === false
    && config('sybi.vending.sync_enabled') === false
    && config('internal_beta.cleanup_enabled') === false
    && config('internal_beta.testers_enabled') === true
    && config('database.default') === 'mysql'
    && config('database.connections.mysql.database') === 'vending_attendance_beta'
    && ! in_array('*', config('internal_beta.trusted_proxies', []), true)
    && app(App\Services\FieldIdentity\LocalBetaTesterRegistry::class)->entries() !== [];
if (! $valid) {
    fwrite(STDERR, "Beta configuration precheck failed; review private configuration locally.\n");
    exit(1);
}
echo 'Beta configuration precheck: PASS; registry SHA256='.hash_file('sha256', config('internal_beta.registry_path')).PHP_EOL;
