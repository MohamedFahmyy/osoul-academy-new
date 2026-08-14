<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\ASAP\Enums\DeviceStatus;
use Modules\ASAP\Enums\SessionStatus;
use Modules\ASAP\Models\Device;
use Modules\ASAP\Models\ExamSession;
use Modules\Exam\Models\ExamCategory;
use Modules\Exam\Models\Exam;
use Modules\Exam\Models\ExamAttempt;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('module:seed', ['module' => 'ASAP']);

    $this->student = User::factory()->create(['role' => 'student']);
    
    $this->category = ExamCategory::create([
        'title' => 'Category', 'slug' => 'cat-slug', 'icon' => 'folder', 'status' => true
    ]);

    $instructorUser = User::factory()->create(['role' => 'instructor']);
    $instructor = \App\Models\Instructor::create([
        'user_id' => $instructorUser->id, 'status' => 'approved', 'designation' => 'Instructor',
        'skills' => ['programming'], 'biography' => 'Bio', 'resume' => 'Resume'
    ]);

    $this->exam = Exam::create([
        'title' => 'Exam', 'slug' => 'exam-slug', 'status' => 'published',
        'pass_mark' => 20, 'total_marks' => 100, 'instructor_id' => $instructor->id,
        'exam_category_id' => $this->category->id
    ]);

    $this->attempt = ExamAttempt::create([
        'user_id' => $this->student->id,
        'exam_id' => $this->exam->id,
        'attempt_number' => 1,
        'status' => 'in_progress',
        'start_time' => now(),
    ]);

    $this->actingAs($this->student);

    // Setup active session for telemetry
    $response = $this->get(route('exam-attempts.take', $this->attempt->id));
    $this->bootstrapToken = $response->viewData('page')['props']['bootstrapToken'];
    $this->asapSessionId = $response->viewData('page')['props']['asapSessionId'];

    // Complete Handshake to obtain decryption key
    $handshakePayload = [
        'session_id' => $this->asapSessionId,
        'bootstrap_token' => $this->bootstrapToken,
        'device_uuid' => 'desktop_client_' . $this->student->id,
    ];
    $response2 = $this->postJson(route('api.asap.session.handshake'), $handshakePayload);
    $this->sessionKey = $response2->json('session_key');
    $this->sessionKeyId = $response2->json('session_key_id');

    // Update status to running
    $session = ExamSession::find($this->asapSessionId);
    $session->update(['status' => SessionStatus::RUNNING->value]);
});

it('rejects heartbeat for a non-existent session UUID', function () {
    $payloadData = [
        'is_focused' => true,
        'is_fullscreen' => true,
        'sequence_number' => 1,
        'timestamp' => now()->timestamp,
        'nonce' => 'nonce_abc_123',
    ];
    ksort($payloadData);
    $sig = hash_hmac('sha256', json_encode($payloadData), $this->sessionKey);

    $heartbeat = [
        'session_id' => \Illuminate\Support\Str::uuid()->toString(),
        'session_key_id' => $this->sessionKeyId,
        'payload' => $payloadData,
        'signature' => $sig,
    ];

    $response = $this->postJson(route('api.asap.telemetry.heartbeat'), $heartbeat);
    $response->assertStatus(422); // session_id exists rule checks database
});

it('rejects heartbeat with invalid HMAC-SHA256 signature', function () {
    $payloadData = [
        'is_focused' => true,
        'is_fullscreen' => true,
        'sequence_number' => 1,
        'timestamp' => now()->timestamp,
        'nonce' => 'nonce_abc_123',
    ];

    $heartbeat = [
        'session_id' => $this->asapSessionId,
        'session_key_id' => $this->sessionKeyId,
        'payload' => $payloadData,
        'signature' => 'invalid_signature_characters_here',
    ];

    $response = $this->postJson(route('api.asap.telemetry.heartbeat'), $heartbeat);
    $response->assertStatus(400);
});

it('rejects sequence rollbacks (sequence number decreases or repeats)', function () {
    // 1. Send heartbeat sequence 5 -> expected 200 Success
    $payload1 = [
        'is_focused' => true,
        'is_fullscreen' => true,
        'sequence_number' => 5,
        'timestamp' => now()->timestamp,
        'nonce' => 'nonce_seq_5',
    ];
    ksort($payload1);
    $sig1 = hash_hmac('sha256', json_encode($payload1), $this->sessionKey);

    $response1 = $this->postJson(route('api.asap.telemetry.heartbeat'), [
        'session_id' => $this->asapSessionId,
        'session_key_id' => $this->sessionKeyId,
        'payload' => $payload1,
        'signature' => $sig1,
    ]);
    $response1->assertStatus(200);

    // 2. Send heartbeat sequence 4 (rollback) -> expected 400 Bad Request
    $payload2 = [
        'is_focused' => true,
        'is_fullscreen' => true,
        'sequence_number' => 4,
        'timestamp' => now()->timestamp,
        'nonce' => 'nonce_seq_4',
    ];
    ksort($payload2);
    $sig2 = hash_hmac('sha256', json_encode($payload2), $this->sessionKey);

    $response2 = $this->postJson(route('api.asap.telemetry.heartbeat'), [
        'session_id' => $this->asapSessionId,
        'session_key_id' => $this->sessionKeyId,
        'payload' => $payload2,
        'signature' => $sig2,
    ]);
    $response2->assertStatus(400);

    // 3. Send heartbeat sequence 5 (repeat) -> expected 400 Bad Request
    $payload3 = [
        'is_focused' => true,
        'is_fullscreen' => true,
        'sequence_number' => 5,
        'timestamp' => now()->timestamp,
        'nonce' => 'nonce_seq_5_repeat',
    ];
    ksort($payload3);
    $sig3 = hash_hmac('sha256', json_encode($payload3), $this->sessionKey);

    $response3 = $this->postJson(route('api.asap.telemetry.heartbeat'), [
        'session_id' => $this->asapSessionId,
        'session_key_id' => $this->sessionKeyId,
        'payload' => $payload3,
        'signature' => $sig3,
    ]);
    $response3->assertStatus(400);
});

it('rejects stale heartbeat timestamps (out of 300 seconds window)', function () {
    // 1. Send heartbeat with stale timestamp (-301 seconds) -> expected 400
    $payload1 = [
        'is_focused' => true,
        'is_fullscreen' => true,
        'sequence_number' => 10,
        'timestamp' => now()->subSeconds(301)->timestamp,
        'nonce' => 'nonce_stale_1',
    ];
    ksort($payload1);
    $sig1 = hash_hmac('sha256', json_encode($payload1), $this->sessionKey);

    $response1 = $this->postJson(route('api.asap.telemetry.heartbeat'), [
        'session_id' => $this->asapSessionId,
        'session_key_id' => $this->sessionKeyId,
        'payload' => $payload1,
        'signature' => $sig1,
    ]);
    $response1->assertStatus(400);
});
