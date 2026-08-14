<?php
require __DIR__ . '/../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$sessionId = 'c5d26c9d-85c3-44bb-a80e-8f53a0de90d2';

// 1. Wait for handshake (READY/RUNNING status)
$sessionReady = false;
$startTime = time();
echo "Waiting for session handshake...\n";
while (time() - $startTime < 20) {
    $session = \Modules\ASAP\Models\ExamSession::find($sessionId);
    if ($session && ($session->status === 'ready' || $session->status === 'running')) {
        $sessionReady = true;
        break;
    }
    sleep(1);
}

if (!$sessionReady) {
    echo "FAIL_HANDSHAKE\n";
    exit(1);
}
echo "ASAP Handshake completed successfully, session running!\n";

// 2. Wait for at least 3 telemetry heartbeats
$heartbeatsCount = 0;
$startTime = time();
echo "Waiting for at least 3 telemetry heartbeats to be recorded...\n";
while (time() - $startTime < 50) {
    // Run queue worker to process any queued heartbeats
    \Illuminate\Support\Facades\Artisan::call('queue:work', [
        '--once' => true,
        '--queue' => 'default'
    ]);

    $heartbeatsCount = \Modules\ASAP\Models\Telemetry::where('session_id', $sessionId)->count();
    if ($heartbeatsCount >= 3) {
        break;
    }
    sleep(2);
}

if ($heartbeatsCount < 3) {
    echo "FAIL_TELEMETRY Received: $heartbeatsCount\n";
    exit(1);
}

echo "SUCCESS_TELEMETRY\n";
exit(0);
