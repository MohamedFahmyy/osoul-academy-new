<?php
require __DIR__ . '/../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$sessionId = 'c5d26c9d-85c3-44bb-a80e-8f53a0de90d2';
$attemptId = 7;

// Clean up old incidents and events for this session to reset risk state
\Modules\ASAP\Models\SecurityEvent::where('session_id', $sessionId)->delete();
\Modules\ASAP\Models\Incident::where('session_id', $sessionId)->delete();
\Modules\ASAP\Models\Telemetry::where('session_id', $sessionId)->delete();

$session = \Modules\ASAP\Models\ExamSession::find($sessionId);
$session->update([
    'status' => 'created',
    'expires_at' => now()->addHours(4),
    'risk_score' => 0.00,
    'bootstrap_token' => null,
    'bootstrap_token_expires_at' => null,
]);

$token = \Modules\ASAP\Services\BootstrapToken::generate($sessionId, $attemptId, 60);
$hash = hash('sha256', $token);
$session->update([
    'bootstrap_token' => $hash,
    'bootstrap_token_expires_at' => now()->addMinutes(60),
]);

$version = config('asap.active_key_version', 2);
$activeKey = config("asap.keys.{$version}");
if (!$activeKey) {
    throw new \Exception("Signing key for version {$version} not found.");
}
$signature = hash_hmac('sha256', $token . '|' . $attemptId, $activeKey);
$url = "asap://open?bootstrapToken=" . urlencode($token) . "&attempt=" . $attemptId . "&signature=" . $signature;
file_put_contents('c:/laragon/www/mentor-lms-learning-management-system/storage/testing/asap_generated_url.txt', $url);
echo "Generated and wrote URL to file.";
