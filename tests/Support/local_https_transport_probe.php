<?php

// Explicit, read-only transport probe. No Laravel bootstrap, credentials or DB.
if (PHP_SAPI !== 'cli' || ! in_array('--run', $argv, true)) {
    fwrite(STDERR, "Use --run; optional --isolated targets the loopback test fixture.\n");
    exit(2);
}
$root = dirname(__DIR__, 2);
$isolated = in_array('--isolated', $argv, true);
$url = $isolated ? 'https://127.0.0.1:8444/' : 'https://192.168.101.15:8443/up';
$failed = false;
foreach ([1024, 16384, 65536, 131072, 1048576, 7056000] as $padding) {
    $body = json_encode(['diagnostic_padding' => str_repeat('x', $padding)], JSON_THROW_ON_ERROR);
    $context = stream_context_create([
        'ssl' => [
            'cafile' => $root.'/storage/framework/local-https/ca.pem',
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            // Explicit certificate identity; loopback reaches the same local test server.
            'peer_name' => '192.168.101.15',
        ],
        'http' => [
            'method' => $isolated ? 'POST' : 'GET',
            'timeout' => 8,
            'ignore_errors' => true,
            'follow_location' => 0,
            'header' => "Content-Type: application/json\r\nConnection: close\r\n",
            'content' => $body,
        ],
    ]);
    unset($http_response_header);
    $started = microtime(true);
    $response = @file_get_contents($url, false, $context);
    $status = $http_response_header[0] ?? 'NO_RESPONSE';
    $passed = $response !== false && preg_match('/^HTTP\/\S+ 200(?: |$)/', $status);
    if ($isolated) {
        $receipt = json_decode($response ?: '', true);
        $passed = $passed && ($receipt['bytes'] ?? -1) === strlen($body)
            && hash_equals(hash('sha256', $body), (string) ($receipt['sha256'] ?? ''));
    }
    $failed = $failed || ! $passed;
    echo json_encode([
        'target' => $isolated ? 'ISOLATED_FIXTURE' : 'DEMO_UP_READ_ONLY',
        'bytes' => strlen($body),
        'seconds' => round(microtime(true) - $started, 3),
        'status' => $status,
        'result' => $passed ? 'PASS' : 'FAIL',
        'integrity_checked' => $isolated,
    ], JSON_THROW_ON_ERROR).PHP_EOL;
}
exit($failed ? 1 : 0);
