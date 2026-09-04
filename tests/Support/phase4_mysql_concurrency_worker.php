<?php

use App\Models\Device;
use App\Services\Vending\VendingAttendanceBatchReceiverService;
use App\Services\Vending\VendingAttendanceReceiverService;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $mode, $deviceId, $payloadPath, $startAt] = $argv;
$payload = json_decode((string) file_get_contents($payloadPath), true, 512, JSON_THROW_ON_ERROR);
$waitMicroseconds = max(0, (int) (((float) $startAt - microtime(true)) * 1_000_000));
if ($waitMicroseconds > 0) {
    usleep($waitMicroseconds);
}

$device = Device::query()->findOrFail((int) $deviceId);
$result = $mode === 'batch'
    ? app(VendingAttendanceBatchReceiverService::class)->receive($device, ['events' => $payload])
    : app(VendingAttendanceReceiverService::class)->receive($device, $payload);

unset($result['http_status']);
fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
