<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Laravel\Sanctum\Sanctum;
use Modules\ASAP\Enums\DeviceStatus;
use Modules\ASAP\Enums\SessionStatus;
use Modules\ASAP\Enums\IncidentStatus;
use Modules\ASAP\Models\Device;
use Modules\ASAP\Models\ExamSession;
use Modules\ASAP\Models\Policy;
use Modules\ASAP\Models\SecurityEvent;
use Modules\ASAP\Models\Incident;
use Modules\Exam\Models\ExamCategory;
use Modules\Exam\Models\Exam;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Run the module seeders
    $this->artisan('module:seed', ['module' => 'ASAP']);

    // Create user & authenticate
    $this->user = User::factory()->create(['role' => 'student']);
    Sanctum::actingAs($this->user);

    // Setup Category & Exam
    $this->category = ExamCategory::create([
        'title' => 'API Test Category',
        'slug' => 'api-test-category',
        'icon' => 'folder',
        'status' => true,
    ]);

    $this->instructorUser = User::factory()->create(['role' => 'instructor']);
    $this->instructor = \App\Models\Instructor::create([
        'user_id' => $this->instructorUser->id,
        'status' => 'approved',
        'designation' => 'Instructor',
        'skills' => ['programming'],
        'biography' => 'Bio',
        'resume' => 'Resume',
    ]);

    $this->exam = Exam::create([
        'title' => 'API Secure Exam',
        'slug' => 'api-secure-exam',
        'status' => 'published',
        'pass_mark' => 20,
        'total_marks' => 100,
        'instructor_id' => $this->instructor->id,
        'exam_category_id' => $this->category->id,
    ]);
});

it('starts a new exam session successfully and rejects duplicates', function () {
    $payload = [
        'device_uuid' => 'device_abc_123',
        'device_name' => 'Chrome-Desktop',
        'operating_system' => 'Windows 10',
        'hardware_hash' => 'hash_xyz_999',
        'hardware_version' => '1.0.0',
        'exam_id' => $this->exam->id,
    ];

    $response = $this->postJson(route('api.asap.session.start'), $payload);

    $response->assertStatus(201)
        ->assertJsonPath('status', 'success')
        ->assertJsonStructure([
            'status',
            'session' => [
                'id',
                'status',
                'risk_score',
            ],
            'credentials' => [
                'session_key_id',
                'session_key',
            ]
        ]);

    $data = $response->json();
    $rawKey = $data['credentials']['session_key'];
    $keyHash = hash('sha256', $rawKey);

    // Verify key encrypted in database
    $session = ExamSession::find($data['session']['id']);
    expect($session)->not->toBeNull()
        ->and($session->session_key_hash)->toBe($keyHash)
        ->and(Crypt::decryptString($session->session_key_encrypted))->toBe($rawKey);

    // Test Duplicate prevention
    $dupResponse = $this->postJson(route('api.asap.session.start'), $payload);
    $dupResponse->assertStatus(409)
        ->assertJsonPath('error_code', 'ASAP-1007');
});

it('validates heartbeat telemetry signatures and logs telemetry', function () {
    // 1. Create active session
    $device = Device::create([
        'id' => \Illuminate\Support\Str::uuid()->toString(),
        'uuid' => 'device_111',
        'name' => 'Desktop',
        'operating_system' => 'Mac OS',
        'status' => DeviceStatus::VERIFIED,
        'hardware_hash' => 'hash111',
        'hardware_version' => '1.0',
    ]);

    $rawKey = \Illuminate\Support\Str::random(32);
    $policy = Policy::where('is_default', true)->first();

    $session = ExamSession::create([
        'id' => \Illuminate\Support\Str::uuid()->toString(),
        'device_id' => $device->id,
        'user_id' => $this->user->id,
        'exam_id' => $this->exam->id,
        'policy_id' => $policy->id,
        'status' => SessionStatus::RUNNING,
        'session_key_id' => 'key_111',
        'session_key_hash' => hash('sha256', $rawKey),
        'session_key_encrypted' => Crypt::encryptString($rawKey),
        'expires_at' => now()->addHours(2),
        'risk_score' => 0.00,
    ]);

    // 2. Submit invalid signature heartbeat
    $invalidHeartbeat = [
        'session_id' => $session->id,
        'session_key_id' => 'key_111',
        'payload' => [
            'is_focused' => true,
            'is_fullscreen' => true,
        ],
        'signature' => 'invalid_signature_here',
    ];

    $response = $this->postJson(route('api.asap.telemetry.heartbeat'), $invalidHeartbeat);
    $response->assertStatus(400)
        ->assertJsonPath('error_code', 'ASAP-1004');

    // 3. Submit valid signature heartbeat
    $payloadData = [
        'is_focused' => true,
        'is_fullscreen' => true,
    ];
    // Signature computed recursively: ksort -> json_encode -> hmac
    $normalized = $payloadData;
    ksort($normalized);
    $sig = hash_hmac('sha256', json_encode($normalized), $rawKey);

    $validHeartbeat = [
        'session_id' => $session->id,
        'session_key_id' => 'key_111',
        'payload' => $payloadData,
        'signature' => $sig,
    ];

    $response2 = $this->postJson(route('api.asap.telemetry.heartbeat'), $validHeartbeat);
    $response2->assertStatus(200)
        ->assertJsonPath('status', 'success');

    // Assert Telemetry Received event was dispatched (since listener is queued, we check database if synchronous or verify database entry if queue is sync for tests)
    $this->assertDatabaseHas('asap_telemetries', [
        'session_id' => $session->id,
    ]);
});

it('verifies security event logging, idempotency, cooldown windows, and thresholds', function () {
    // 1. Setup session
    $device = Device::create([
        'id' => \Illuminate\Support\Str::uuid()->toString(),
        'uuid' => 'device_222',
        'name' => 'Desktop',
        'operating_system' => 'Linux',
        'status' => DeviceStatus::VERIFIED,
        'hardware_hash' => 'hash222',
        'hardware_version' => '1.0',
    ]);

    $rawKey = \Illuminate\Support\Str::random(32);
    $policy = Policy::where('is_default', true)->first();

    $session = ExamSession::create([
        'id' => \Illuminate\Support\Str::uuid()->toString(),
        'device_id' => $device->id,
        'user_id' => $this->user->id,
        'exam_id' => $this->exam->id,
        'policy_id' => $policy->id,
        'status' => SessionStatus::RUNNING,
        'session_key_id' => 'key_222',
        'session_key_hash' => hash('sha256', $rawKey),
        'session_key_encrypted' => Crypt::encryptString($rawKey),
        'expires_at' => now()->addHours(2),
        'risk_score' => 0.00,
    ]);

    $eventUuid = \Illuminate\Support\Str::uuid()->toString();
    $occurredAt = now()->format('Y-m-d H:i:s');

    // Setup input payload matching validation rules
    $inputPayload = [
        'session_id' => $session->id,
        'session_key_id' => 'key_222',
        'event_uuid' => $eventUuid,
        'event_code' => 'WINDOW_UNFOCUS',
        'payload' => ['app' => 'Slack'],
        'severity' => 'warning',
        'source' => 'client',
        'category' => 'window',
        'client_sequence' => 1,
        'occurred_at' => $occurredAt,
    ];

    // Compute HMAC
    $signaturePayload = $inputPayload;
    $signaturePayload['payload'] = $inputPayload['payload'];
    $signaturePayload['client_sequence'] = (int) $inputPayload['client_sequence'];
    ksort($signaturePayload);
    $sig = hash_hmac('sha256', json_encode($signaturePayload), $rawKey);

    $inputPayload['signature'] = $sig;

    // 2. Post Event (First Time)
    $response = $this->postJson(route('api.asap.event.report'), $inputPayload);
    $response->assertStatus(200);

    // Verify event in DB and that risk score increased by the rule weight (WINDOW_UNFOCUS has weight 10.00)
    $this->assertDatabaseHas('asap_security_events', [
        'id' => $eventUuid,
        'risk_delta' => 10.00,
    ]);

    $session->refresh();
    expect($session->risk_score)->toBe(10.00);

    // 3. Post Same Event again (Idempotency Check)
    $responseDup = $this->postJson(route('api.asap.event.report'), $inputPayload);
    $responseDup->assertStatus(200);

    // Assert count remains 1 and score didn't double
    expect(SecurityEvent::where('session_id', $session->id)->count())->toBe(1);

    // 4. Cooldown Throttle Check
    // Post a different event of same event_code (WINDOW_UNFOCUS) within the cooldown window (cooldown is 60s for WINDOW_UNFOCUS)
    $eventUuid2 = \Illuminate\Support\Str::uuid()->toString();
    $inputPayload2 = $inputPayload;
    $inputPayload2['event_uuid'] = $eventUuid2;
    $inputPayload2['client_sequence'] = 2;

    $signaturePayload2 = $inputPayload2;
    unset($signaturePayload2['signature']);
    ksort($signaturePayload2);
    $sig2 = hash_hmac('sha256', json_encode($signaturePayload2), $rawKey);
    $inputPayload2['signature'] = $sig2;

    $responseThrottled = $this->postJson(route('api.asap.event.report'), $inputPayload2);
    $responseThrottled->assertStatus(200);

    // The event should be logged with 0.00 risk weight and action 'throttled'
    $this->assertDatabaseHas('asap_security_events', [
        'id' => $eventUuid2,
        'risk_delta' => 0.00,
        'policy_action' => 'throttled',
    ]);

    // 5. Test Threshold Escalations (Threshold WARNING: 40.00, PAUSED: 60.00, TERMINATED: 85.00)
    // Post high weight event to cross PAUSED threshold (PROCESS_BLACKLIST_DETECTED weight is 60.00)
    $eventUuid3 = \Illuminate\Support\Str::uuid()->toString();
    $inputPayload3 = [
        'session_id' => $session->id,
        'session_key_id' => 'key_222',
        'event_uuid' => $eventUuid3,
        'event_code' => 'PROCESS_BLACKLIST_DETECTED',
        'payload' => ['process' => 'cheat.exe'],
        'severity' => 'terminate',
        'source' => 'client',
        'category' => 'process',
        'client_sequence' => 3,
        'occurred_at' => $occurredAt,
    ];

    $sigPayload3 = $inputPayload3;
    ksort($sigPayload3);
    $sig3 = hash_hmac('sha256', json_encode($sigPayload3), $rawKey);
    $inputPayload3['signature'] = $sig3;

    $responseEscalated = $this->postJson(route('api.asap.event.report'), $inputPayload3);
    $responseEscalated->assertStatus(200);

    $session->refresh();
    // Risk score should be: 10 (unfocus) + 60 (blacklist) = 70.00
    expect($session->risk_score)->toBe(70.00)
        ->and($session->status)->toBe(SessionStatus::PAUSED);

    // Assert that an Incident was automatically opened synchronously
    $this->assertDatabaseHas('asap_incidents', [
        'session_id' => $session->id,
        'status' => IncidentStatus::OPEN->value,
    ]);
});

it('verifies key rotation, revocation, and expiration operations', function () {
    $device = Device::create([
        'id' => \Illuminate\Support\Str::uuid()->toString(),
        'uuid' => 'device_rot',
        'name' => 'PC',
        'operating_system' => 'Windows',
        'status' => DeviceStatus::VERIFIED,
    ]);

    $policy = Policy::where('is_default', true)->first();
    $session = ExamSession::create([
        'id' => \Illuminate\Support\Str::uuid()->toString(),
        'device_id' => $device->id,
        'user_id' => $this->user->id,
        'exam_id' => $this->exam->id,
        'policy_id' => $policy->id,
        'status' => SessionStatus::RUNNING,
        'expires_at' => now()->addHours(1),
    ]);

    $keyService = app(\Modules\ASAP\Services\SessionKeyService::class);

    // Generate Key
    $keys = $keyService->generateKey($session);
    expect($keys['raw_key'])->not->toBeEmpty();

    $session->update([
        'session_key_id' => $keys['session_key_id'],
        'session_key_hash' => $keys['session_key_hash'],
        'session_key_encrypted' => $keys['session_key_encrypted'],
    ]);

    // Check expiration
    expect($keyService->isExpired($session))->toBeFalse();
    $session->update(['expires_at' => now()->subMinutes(1)]);
    expect($keyService->isExpired($session))->toBeTrue();

    // Rotate Key
    $rotatedKeys = $keyService->rotateKey($session);
    expect($rotatedKeys['session_key_id'])->not->toBe($keys['session_key_id']);

    // Revoke Key
    $keyService->revokeKey($session);
    expect($session->session_key_id)->toBeNull();
});

it('verifies Redis caching of session state and device blacklists', function () {
    $device = Device::create([
        'id' => \Illuminate\Support\Str::uuid()->toString(),
        'uuid' => 'device_cache_test',
        'name' => 'PC',
        'operating_system' => 'Windows',
        'status' => DeviceStatus::VERIFIED,
    ]);

    $policy = Policy::where('is_default', true)->first();
    $session = ExamSession::create([
        'id' => \Illuminate\Support\Str::uuid()->toString(),
        'device_id' => $device->id,
        'user_id' => $this->user->id,
        'exam_id' => $this->exam->id,
        'policy_id' => $policy->id,
        'status' => SessionStatus::RUNNING,
    ]);

    $cacheService = app(\Modules\ASAP\Services\ASAPCacheService::class);

    // Session cache verification
    $cacheService->putSessionState($session);
    $state = $cacheService->getSessionState($session->id);
    expect($state)->not->toBeNull()
        ->and($state['status'])->toBe(SessionStatus::RUNNING->value);

    // Device blacklist cache verification
    expect($cacheService->isDeviceBlacklisted('device_cache_test'))->toBeFalse();
    $cacheService->blacklistDevice('device_cache_test');
    expect($cacheService->isDeviceBlacklisted('device_cache_test'))->toBeTrue();
});

it('verifies composite Rate Limiting on endpoints', function () {
    // We attempt to trigger rate limiting on session start (max 5 requests per minute)
    $payload = [
        'device_uuid' => 'rate_limit_device',
        'device_name' => 'Chrome-Desktop',
        'operating_system' => 'Windows 10',
        'hardware_hash' => 'hash_rate_limit',
        'hardware_version' => '1.0.0',
        'exam_id' => $this->exam->id,
    ];

    // Trigger 5 calls (allowed)
    for ($i = 0; $i < 5; $i++) {
        $this->postJson(route('api.asap.session.start'), $payload);
    }

    // 6th call should be rate limited (429)
    $response = $this->postJson(route('api.asap.session.start'), $payload);
    expect($response->status())->toBe(429);
});

it('verifies secure session handshake endpoint returns credentials', function () {
    $device = Device::create([
        'id' => \Illuminate\Support\Str::uuid()->toString(),
        'uuid' => 'device_handshake',
        'name' => 'PC',
        'operating_system' => 'Windows',
        'status' => DeviceStatus::VERIFIED,
    ]);

    $policy = Policy::where('is_default', true)->first();
    $bootstrapToken = \Illuminate\Support\Str::random(32);
    $session = ExamSession::create([
        'id' => \Illuminate\Support\Str::uuid()->toString(),
        'device_id' => $device->id,
        'user_id' => $this->user->id,
        'exam_id' => $this->exam->id,
        'policy_id' => $policy->id,
        'status' => SessionStatus::RUNNING,
        'session_key_id' => 'key_handshake_id',
        'session_key_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('secret_key_123'),
        'bootstrap_token' => hash('sha256', $bootstrapToken),
        'bootstrap_token_expires_at' => now()->addMinutes(2),
        'expires_at' => now()->addHours(1),
    ]);

    $payload = [
        'session_id' => $session->id,
        'bootstrap_token' => $bootstrapToken,
    ];

    $response = $this->postJson(route('api.asap.session.handshake'), $payload);
    $response->assertStatus(200)
        ->assertJson([
            'status' => 'success',
            'session_id' => $session->id,
            'session_key_id' => 'key_handshake_id',
            'session_key' => 'secret_key_123',
        ]);
});

it('verifies secure session status show endpoint returns status', function () {
    $device = Device::create([
        'id' => \Illuminate\Support\Str::uuid()->toString(),
        'uuid' => 'device_status_show',
        'name' => 'PC',
        'operating_system' => 'Windows',
        'status' => DeviceStatus::VERIFIED,
    ]);

    $policy = Policy::where('is_default', true)->first();
    $session = ExamSession::create([
        'id' => \Illuminate\Support\Str::uuid()->toString(),
        'device_id' => $device->id,
        'user_id' => $this->user->id,
        'exam_id' => $this->exam->id,
        'policy_id' => $policy->id,
        'status' => SessionStatus::RUNNING,
        'session_key_id' => 'key_status_id',
        'session_key_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('secret_key_123'),
        'expires_at' => now()->addHours(1),
    ]);

    $response = $this->getJson(route('api.asap.session.show', ['id' => $session->id]));
    $response->assertStatus(200)
        ->assertJson([
            'status' => 'success',
            'session' => [
                'id' => $session->id,
                'status' => 'running',
            ],
        ]);
});
