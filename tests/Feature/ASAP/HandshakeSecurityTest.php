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
});

it('verifies handshake yields a valid 32-byte key', function () {
    // 1. Visit take page
    $response = $this->get(route('exam-attempts.take', $this->attempt->id));
    $bootstrapToken = $response->viewData('page')['props']['bootstrapToken'];
    $asapSessionId = $response->viewData('page')['props']['asapSessionId'];

    // 2. Perform handshake
    $handshakePayload = [
        'session_id' => $asapSessionId,
        'bootstrap_token' => $bootstrapToken,
        'device_uuid' => 'desktop_client_' . $this->student->id,
    ];

    $response2 = $this->postJson(route('api.asap.session.handshake'), $handshakePayload);
    $response2->assertStatus(200)
        ->assertJsonStructure([
            'status',
            'session_id',
            'session_key_id',
            'session_key',
        ]);

    $key = $response2->json('session_key');
    expect(strlen($key))->toBe(32);
});

it('verifies custom protocol URL link structure and signature validation', function () {
    // 1. Visit take page to get link
    $response = $this->get(route('exam-attempts.take', $this->attempt->id));
    $launchUrl = $response->viewData('page')['props']['asapProtocolUrl'];
    $bootstrapToken = $response->viewData('page')['props']['bootstrapToken'];

    // 2. Verify structure
    expect($launchUrl)->toStartWith('asap://open?')
        ->and($launchUrl)->toContain('bootstrapToken=')
        ->and($launchUrl)->toContain('attempt=')
        ->and($launchUrl)->toContain('signature=');

    // 3. Cryptographically verify signature
    $queryStr = parse_url($launchUrl, PHP_URL_QUERY);
    parse_str($queryStr, $params);

    $tokenParam = $params['bootstrapToken'];
    $attemptParam = $params['attempt'];
    $sigParam = $params['signature'];

    $version = config('asap.active_key_version', 2);
    $activeKey = config("asap.keys.{$version}");
    
    $expectedSig = hash_hmac('sha256', $tokenParam . '|' . $attemptParam, $activeKey);
    expect(hash_equals($expectedSig, $sigParam))->toBeTrue();
});

it('rejects handshake device mismatches (session hijacking check)', function () {
    // 1. Visit take page
    $response = $this->get(route('exam-attempts.take', $this->attempt->id));
    $bootstrapToken = $response->viewData('page')['props']['bootstrapToken'];
    $asapSessionId = $response->viewData('page')['props']['asapSessionId'];

    // 2. Request handshake with a different device UUID -> expected 403 Device Mismatch
    $handshakePayload = [
        'session_id' => $asapSessionId,
        'bootstrap_token' => $bootstrapToken,
        'device_uuid' => 'attacker_device_uuid_9999',
    ];

    $response2 = $this->postJson(route('api.asap.session.handshake'), $handshakePayload);
    $response2->assertStatus(403);
});

it('enforces rate limiting on the handshake endpoint', function () {
    // 1. Visit take page
    $response = $this->get(route('exam-attempts.take', $this->attempt->id));
    $bootstrapToken = $response->viewData('page')['props']['bootstrapToken'];
    $asapSessionId = $response->viewData('page')['props']['asapSessionId'];

    $handshakePayload = [
        'session_id' => $asapSessionId,
        'bootstrap_token' => $bootstrapToken,
        'device_uuid' => 'desktop_client_' . $this->student->id,
    ];

    // 2. Run 5 allowed handshake requests (we bypass single-use limit check in this test by mocking the database check if needed, or by just making requests that hit rate limiter)
    // Actually, the rate limiter uses the IP/UserId combination, so we can send any payload (even failing ones) to trigger the throttle middleware.
    // Let's call the handshake route with bad requests to trigger the rate limiter limit of 5 per minute
    for ($i = 0; $i < 5; $i++) {
        $this->postJson(route('api.asap.session.handshake'), []);
    }

    // The 6th request must return 429 Throttle
    $responseThrottle = $this->postJson(route('api.asap.session.handshake'), []);
    expect($responseThrottle->status())->toBe(429);
});

it('rejects handshake if the launch signature is invalid', function () {
    $response = $this->get(route('exam-attempts.take', $this->attempt->id));
    $bootstrapToken = $response->viewData('page')['props']['bootstrapToken'];

    $handshakePayload = [
        'bootstrap_token' => $bootstrapToken,
        'attempt_id' => $this->attempt->id,
        'signature' => 'invalid_launch_signature_here',
    ];

    $response2 = $this->postJson(route('api.asap.session.handshake'), $handshakePayload);
    $response2->assertStatus(400);
});

it('rejects handshake if the attempt ID mismatches', function () {
    $response = $this->get(route('exam-attempts.take', $this->attempt->id));
    $bootstrapToken = $response->viewData('page')['props']['bootstrapToken'];

    $handshakePayload = [
        'bootstrap_token' => $bootstrapToken,
        'attempt_id' => 9999, // mismatched attempt ID
    ];

    $response2 = $this->postJson(route('api.asap.session.handshake'), $handshakePayload);
    $response2->assertStatus(400);
});

it('rejects handshake if the attempt status is not in progress', function () {
    $response = $this->get(route('exam-attempts.take', $this->attempt->id));
    $bootstrapToken = $response->viewData('page')['props']['bootstrapToken'];

    $this->attempt->update(['status' => 'completed']);

    $handshakePayload = [
        'bootstrap_token' => $bootstrapToken,
        'attempt_id' => $this->attempt->id,
    ];

    $response2 = $this->postJson(route('api.asap.session.handshake'), $handshakePayload);
    $response2->assertStatus(400);
});
