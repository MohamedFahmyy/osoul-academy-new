<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Modules\ASAP\Enums\DeviceStatus;
use Modules\ASAP\Enums\SessionStatus;
use Modules\ASAP\Models\Device;
use Modules\ASAP\Models\ExamSession;
use Modules\ASAP\Models\Telemetry;
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

    $this->question = \Modules\Exam\Models\ExamQuestion::create([
        'exam_id' => $this->exam->id,
        'question_type' => 'subjective',
        'title' => 'Subjective Question',
        'description' => 'Describe PHP.',
        'marks' => 10,
    ]);

    $this->actingAs($this->student);
});

it('tests full state machine transitions and audit log generation', function () {
    Log::shouldReceive('channel')
        ->with('asap_audit')
        ->andReturnSelf()
        ->shouldReceive('log')
        ->atLeast()->once();

    // 1. Session created state -> CREATED
    $response = $this->get(route('exam-attempts.take', $this->attempt->id));
    $response->assertStatus(200);

    $bootstrapToken = $response->viewData('page')['props']['bootstrapToken'];
    $asapSessionId = $response->viewData('page')['props']['asapSessionId'];

    $session = ExamSession::find($asapSessionId);
    expect($session->status)->toBe(SessionStatus::CREATED);

    // 2. Handshake completes -> READY
    $handshakePayload = [
        'session_id' => $asapSessionId,
        'bootstrap_token' => $bootstrapToken,
        'device_uuid' => 'desktop_client_' . $this->student->id,
    ];
    $response2 = $this->postJson(route('api.asap.session.handshake'), $handshakePayload);
    $response2->assertStatus(200);

    $session->refresh();
    expect($session->status)->toBe(SessionStatus::READY);

    $sessionKey = $response2->json('session_key');
    $sessionKeyId = $response2->json('session_key_id');

    // 3. Heartbeat starts -> RUNNING
    // Update status to running as simulated by client
    $session->update(['status' => SessionStatus::RUNNING]);

    $payload1 = [
        'is_focused' => true,
        'is_fullscreen' => true,
        'sequence_number' => 1,
        'timestamp' => now()->timestamp,
        'nonce' => 'nonce_lifecycle_1',
    ];
    ksort($payload1);
    $sig1 = hash_hmac('sha256', json_encode($payload1), $sessionKey);

    $response3 = $this->postJson(route('api.asap.telemetry.heartbeat'), [
        'session_id' => $asapSessionId,
        'session_key_id' => $sessionKeyId,
        'payload' => $payload1,
        'signature' => $sig1,
    ]);
    $response3->assertStatus(200);

    $session->refresh();
    expect($session->status)->toBe(SessionStatus::RUNNING);

    // 4. Heartbeat timeout -> WARNING
    // Fast forward 2 minutes
    Carbon::setTestNow(now()->addMinutes(2));

    // Reload take page which triggers heartbeat check
    $this->get(route('exam-attempts.take', $this->attempt->id));

    $session->refresh();
    expect($session->status)->toBe(SessionStatus::WARNING);

    // Reset Carbon test time
    Carbon::setTestNow();
    $session->update(['status' => SessionStatus::RUNNING]);

    // 5. Exam submission -> COMPLETED
    $responseSubmit = $this->postJson(route('exam-attempts.submit', $this->attempt->id), [
        'exam_attempt_id' => $this->attempt->id,
        'answers' => [
            [
                'exam_question_id' => $this->question->id,
                'answer_data' => 'This is a sample answer.',
            ]
        ],
    ]);
    $responseSubmit->assertStatus(302); // Redirects to exam page

    $session->refresh();
    expect($session->status)->toBe(SessionStatus::COMPLETED);

    // 6. Subsequent Heartbeats reject
    $payload2 = [
        'is_focused' => true,
        'is_fullscreen' => true,
        'sequence_number' => 2,
        'timestamp' => now()->timestamp,
        'nonce' => 'nonce_lifecycle_2',
    ];
    ksort($payload2);
    $sig2 = hash_hmac('sha256', json_encode($payload2), $sessionKey);

    $response4 = $this->postJson(route('api.asap.telemetry.heartbeat'), [
        'session_id' => $asapSessionId,
        'session_key_id' => $sessionKeyId,
        'payload' => $payload2,
        'signature' => $sig2,
    ]);
    $response4->assertStatus(400); // Session is no longer active
});

it('redirects student on take if exam attempt is completed', function () {
    $this->attempt->update(['status' => 'completed']);

    $response = $this->get(route('exam-attempts.take', $this->attempt->id));
    $response->assertStatus(302)
        ->assertRedirect(route('student.exam.show', [
            'id' => $this->attempt->exam_id,
            'tab' => 'attempts',
        ]));
});
