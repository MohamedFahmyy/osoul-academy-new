<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
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

it('calculates latency percentiles and checks for N+1 query loops', function () {
    // 1. Load Take page & assert no N+1 query loop
    DB::enableQueryLog();
    $response = $this->get(route('exam-attempts.take', $this->attempt->id));
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $bootstrapToken = $response->viewData('page')['props']['bootstrapToken'];
    $asapSessionId = $response->viewData('page')['props']['asapSessionId'];

    // Verify query count is reasonable (e.g. < 40 queries) and no duplicates
    expect(count($queries))->toBeLessThan(40);
    $queryStrings = array_column($queries, 'query');
    $uniqueQueryStrings = array_unique($queryStrings);
    // If the difference between unique and total is large, it indicates N+1
    expect(count($queryStrings) - count($uniqueQueryStrings))->toBeLessThan(20);

    // 2. Measure Handshake Latency Percentiles (P50, P95, P99)
    $handshakeDurations = [];
    for ($i = 0; $i < 10; $i++) {
        // We need a fresh session for each handshake to succeed (due to single-use limit)
        $session = ExamSession::create([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'device_id' => Device::first()->id,
            'user_id' => $this->student->id,
            'exam_id' => $this->exam->id,
            'policy_id' => \Modules\ASAP\Models\Policy::first()->id,
            'status' => SessionStatus::CREATED->value,
            'session_key_id' => 'key_perf_' . $i,
            'session_key_hash' => hash('sha256', 'perf_key_' . $i),
            'session_key_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('perf_key_' . $i),
            'bootstrap_token' => hash('sha256', 'tok_perf_' . $i),
            'bootstrap_token_expires_at' => now()->addMinutes(2),
            'expires_at' => now()->addHours(4),
            'risk_score' => 0.00,
        ]);
        // Also mock standard BootstrapToken structure so it passes signature check
        $token = \Modules\ASAP\Services\BootstrapToken::generate($session->id, $this->attempt->id);
        $session->update(['bootstrap_token' => hash('sha256', $token)]);

        $start = microtime(true);
        $this->postJson(route('api.asap.session.handshake'), [
            'session_id' => $session->id,
            'bootstrap_token' => $token,
            'device_uuid' => 'desktop_client_' . $this->student->id,
        ]);
        $handshakeDurations[] = (microtime(true) - $start) * 1000;
    }

    sort($handshakeDurations);
    $p50 = $handshakeDurations[floor(count($handshakeDurations) * 0.50)];
    $p95 = $handshakeDurations[floor(count($handshakeDurations) * 0.95)];
    $p99 = $handshakeDurations[floor(count($handshakeDurations) * 0.99)];

    expect($p50)->toBeLessThan(500)
        ->and($p95)->toBeLessThan(800)
        ->and($p99)->toBeLessThan(1200);
});

it('gracefully handles cache store exceptions (Redis Down fallback test)', function () {
    // 1. Force the Cache to throw connection exception on Redis
    // We mock Cache store to simulate Redis Down
    Cache::partialMock()
        ->shouldReceive('store')
        ->with('redis')
        ->andThrow(new \RuntimeException('Connection refused'));

    // 2. Load Take page -> should complete successfully using ArrayStore fallback
    $response = $this->get(route('exam-attempts.take', $this->attempt->id));
    $response->assertStatus(200);
});
