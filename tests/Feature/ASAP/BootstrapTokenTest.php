<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\ASAP\Enums\DeviceStatus;
use Modules\ASAP\Enums\SessionStatus;
use Modules\ASAP\Models\Device;
use Modules\ASAP\Models\ExamSession;
use Modules\ASAP\Services\BootstrapToken;
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
});

it('verifies bootstrap token structure, payload variables, and HMAC-SHA256 signature', function () {
    // 1. Visit take page to generate token
    $response = $this->get(route('exam-attempts.take', $this->attempt->id));
    $response->assertStatus(200);

    $bootstrapToken = $response->viewData('page')['props']['bootstrapToken'];
    $asapSessionId = $response->viewData('page')['props']['asapSessionId'];

    expect($bootstrapToken)->not->toBeEmpty();

    // 2. Decode the token using BootstrapToken service
    $payload = BootstrapToken::decode($bootstrapToken);
    expect($payload)->not->toBeNull()
        ->and($payload->kid)->toBe(config('asap.active_key_version', 2))
        ->and($payload->sid)->toBe($asapSessionId)
        ->and($payload->aid)->toBe($this->attempt->id)
        ->and($payload->expires_at->isFuture())->toBeTrue();
});

it('verifies that the bootstrap token expires after 16 minutes', function () {
    // 1. Visit take page
    $response = $this->get(route('exam-attempts.take', $this->attempt->id));
    $bootstrapToken = $response->viewData('page')['props']['bootstrapToken'];
    $asapSessionId = $response->viewData('page')['props']['asapSessionId'];

    // 2. Fast forward time 16 minutes using Carbon::setTestNow
    Carbon::setTestNow(now()->addMinutes(16));

    // 3. Attempt secure session handshake -> expected 401
    $handshakePayload = [
        'session_id' => $asapSessionId,
        'bootstrap_token' => $bootstrapToken,
    ];

    $response2 = $this->postJson(route('api.asap.session.handshake'), $handshakePayload);
    $response2->assertStatus(401);

    // Reset Carbon test time
    Carbon::setTestNow();
});

it('prevents bootstrap token reuse (single-use limit)', function () {
    // 1. Visit take page
    $response = $this->get(route('exam-attempts.take', $this->attempt->id));
    $bootstrapToken = $response->viewData('page')['props']['bootstrapToken'];
    $asapSessionId = $response->viewData('page')['props']['asapSessionId'];

    // 2. First Handshake -> expected 200 Success
    $handshakePayload = [
        'session_id' => $asapSessionId,
        'bootstrap_token' => $bootstrapToken,
        'device_uuid' => 'desktop_client_' . $this->student->id,
    ];

    $response2 = $this->postJson(route('api.asap.session.handshake'), $handshakePayload);
    $response2->assertStatus(200);

    // 3. Second Handshake with exact same token -> expected 401
    $response3 = $this->postJson(route('api.asap.session.handshake'), $handshakePayload);
    $response3->assertStatus(401);
});

it('rejects tampered bootstrap token signatures', function () {
    // 1. Visit take page
    $response = $this->get(route('exam-attempts.take', $this->attempt->id));
    $bootstrapToken = $response->viewData('page')['props']['bootstrapToken'];
    $asapSessionId = $response->viewData('page')['props']['asapSessionId'];

    // 2. Alter a character in the signature (signature is after the dot)
    $parts = explode('.', $bootstrapToken);
    $payloadBase64 = $parts[0];
    $sig = $parts[1];
    
    // Change last char
    $lastChar = substr($sig, -1);
    $alteredChar = ($lastChar === 'A') ? 'B' : 'A';
    $alteredSig = substr($sig, 0, -1) . $alteredChar;
    $tamperedToken = $payloadBase64 . '.' . $alteredSig;

    // 3. Handshake with tampered token -> expected 401
    $handshakePayload = [
        'session_id' => $asapSessionId,
        'bootstrap_token' => $tamperedToken,
    ];

    $response2 = $this->postJson(route('api.asap.session.handshake'), $handshakePayload);
    $response2->assertStatus(401);
});
