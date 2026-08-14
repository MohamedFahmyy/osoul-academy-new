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

    // Setup session
    $response = $this->get(route('exam-attempts.take', $this->attempt->id));
    $this->bootstrapToken = $response->viewData('page')['props']['bootstrapToken'];
    $this->asapSessionId = $response->viewData('page')['props']['asapSessionId'];

    $handshakePayload = [
        'session_id' => $this->asapSessionId,
        'bootstrap_token' => $this->bootstrapToken,
        'device_uuid' => 'desktop_client_' . $this->student->id,
    ];
    $response2 = $this->postJson(route('api.asap.session.handshake'), $handshakePayload);
    $this->sessionKey = $response2->json('session_key');
    $this->sessionKeyId = $response2->json('session_key_id');

    $session = ExamSession::find($this->asapSessionId);
    $session->update(['status' => SessionStatus::RUNNING->value]);
});

it('rejects replayed nonces but accepts unique nonces', function () {
    // 1. First heartbeat with nonce "unique_nonce_1" -> expected 200 Success
    $payload1 = [
        'is_focused' => true,
        'is_fullscreen' => true,
        'sequence_number' => 1,
        'timestamp' => now()->timestamp,
        'nonce' => 'unique_nonce_1',
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

    // 2. Re-send exact same heartbeat payload (same nonce) -> expected 400 Bad Request
    $response2 = $this->postJson(route('api.asap.telemetry.heartbeat'), [
        'session_id' => $this->asapSessionId,
        'session_key_id' => $this->sessionKeyId,
        'payload' => $payload1,
        'signature' => $sig1,
    ]);
    $response2->assertStatus(400);

    // 3. Heartbeat with sequence 2 and duplicate nonce "unique_nonce_1" -> expected 400 Bad Request
    $payload3 = $payload1;
    $payload3['sequence_number'] = 2;
    ksort($payload3);
    $sig3 = hash_hmac('sha256', json_encode($payload3), $this->sessionKey);

    $response3 = $this->postJson(route('api.asap.telemetry.heartbeat'), [
        'session_id' => $this->asapSessionId,
        'session_key_id' => $this->sessionKeyId,
        'payload' => $payload3,
        'signature' => $sig3,
    ]);
    $response3->assertStatus(400);

    // 4. Heartbeat with sequence 2 and NEW nonce "unique_nonce_2" -> expected 200 Success
    $payload4 = [
        'is_focused' => true,
        'is_fullscreen' => true,
        'sequence_number' => 2,
        'timestamp' => now()->timestamp,
        'nonce' => 'unique_nonce_2',
    ];
    ksort($payload4);
    $sig4 = hash_hmac('sha256', json_encode($payload4), $this->sessionKey);

    $response4 = $this->postJson(route('api.asap.telemetry.heartbeat'), [
        'session_id' => $this->asapSessionId,
        'session_key_id' => $this->sessionKeyId,
        'payload' => $payload4,
        'signature' => $sig4,
    ]);
    $response4->assertStatus(200);
});
