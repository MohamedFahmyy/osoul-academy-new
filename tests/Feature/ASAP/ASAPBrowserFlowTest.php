<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ASAP\Enums\SessionStatus;
use Modules\ASAP\Models\ExamSession;
use Modules\Exam\Models\ExamCategory;
use Modules\Exam\Models\Exam;
use Modules\Exam\Models\ExamAttempt;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['mail.default' => 'log']);
    $this->seed(\Database\Seeders\SettingsSeeder::class);
    \App\Models\Setting::where('type', 'smtp')->update([
        'fields' => [
            'mail_mailer' => 'log',
            'mail_host' => '127.0.0.1',
            'mail_port' => '2525',
            'mail_username' => 'test',
            'mail_password' => 'test',
            'mail_encryption' => 'null',
            'mail_from_address' => 'test@test.com',
            'mail_from_name' => 'Test',
        ]
    ]);
    $this->artisan('module:seed', ['module' => 'ASAP']);

    // Setup Category & Instructor
    $this->category = ExamCategory::create([
        'title' => 'E2E Category',
        'slug' => 'e2e-category',
        'icon' => 'folder',
        'status' => true,
    ]);

    $instructorUser = User::factory()->create(['role' => 'instructor']);
    $instructor = \App\Models\Instructor::create([
        'user_id' => $instructorUser->id,
        'status' => 'approved',
        'designation' => 'E2E Instructor',
        'skills' => ['programming'],
        'biography' => 'Bio',
        'resume' => 'Resume',
    ]);

    // Create the target test exam
    $this->exam = Exam::create([
        'title' => 'ASAP Security Test Exam',
        'slug' => 'asap-security-test-exam',
        'status' => 'published',
        'pass_mark' => 20,
        'total_marks' => 100,
        'instructor_id' => $instructor->id,
        'exam_category_id' => $this->category->id,
        'max_attempts' => 5,
        'pricing_type' => 'free',
    ]);
});

test('asap web flow integration: register -> login -> enroll -> start -> assert asap protocol launch URL', function () {
    $email = 'asap-e2e-pest@test.local';

    // 1. Register Trainee
    $registerResponse = $this->post(route('register.store'), [
        'name' => 'E2E Pest Candidate',
        'email' => $email,
        'password' => 'SecurePassword123',
        'password_confirmation' => 'SecurePassword123',
        'recaptcha_status' => false,
    ]);
    $registerResponse->assertSessionHasNoErrors();
    $this->assertAuthenticated();
    $registerResponse->assertRedirect(route('student.index', ['tab' => 'courses']));

    $user = auth()->user();
    expect($user->email)->toBe($email);

    // 2. Logout
    $logoutResponse = $this->post(route('logout'));
    $this->assertGuest();
    $logoutResponse->assertRedirect(route('home'));

    // 3. Login Trainee
    $loginResponse = $this->post(route('login.store'), [
        'email' => $email,
        'password' => 'SecurePassword123',
        'recaptcha_status' => false,
    ]);
    $this->assertAuthenticated();
    $loginResponse->assertRedirect(route('category.courses', ['category' => 'all'], absolute: false));

    // 4. Enroll in ASAP Security Test Exam
    $enrollResponse = $this->post(route('exam-enrollments.store'), [
        'user_id' => $user->id,
        'exam_id' => $this->exam->id,
        'enrollment_type' => 'free',
    ]);
    $enrollResponse->assertStatus(302); // Redirect back

    $this->assertDatabaseHas('exam_enrollments', [
        'user_id' => $user->id,
        'exam_id' => $this->exam->id,
    ]);

    // 5. Start Exam Attempt
    $startResponse = $this->post(route('exam-attempts.start', $this->exam->id), [
        'exam_id' => $this->exam->id,
    ]);
    
    $attempt = ExamAttempt::where('user_id', $user->id)
        ->where('exam_id', $this->exam->id)
        ->latest()
        ->first();

    expect($attempt)->not->toBeNull();
    $startResponse->assertRedirect(route('exam-attempts.take', $attempt->id));

    // 6. Access Take Page (Launch Secure Exam Page)
    $takeResponse = $this->get(route('exam-attempts.take', $attempt->id));
    $takeResponse->assertStatus(200);

    // Extract props from Inertia response
    $inertiaPage = $takeResponse->viewData('page');
    $props = $inertiaPage['props'];

    expect($props)->toHaveKeys(['bootstrapToken', 'asapSessionId', 'asapProtocolUrl']);
    
    $token = $props['bootstrapToken'];
    $sessionId = $props['asapSessionId'];
    $launchUrl = $props['asapProtocolUrl'];

    // Verify ASAP Session was created in database
    $session = ExamSession::find($sessionId);
    expect($session)->not->toBeNull()
        ->and($session->status)->toBe(SessionStatus::CREATED)
        ->and($session->user_id)->toBe($user->id)
        ->and($session->exam_id)->toBe($this->exam->id);

    // 7. Parse & Validate Custom Protocol launchUrl components
    $parsed = parse_url($launchUrl);
    expect($parsed['scheme'])->toBe('asap')
        ->and($parsed['host'])->toBe('open');

    parse_str($parsed['query'], $queryParams);

    expect($queryParams)->toHaveKeys(['bootstrapToken', 'attempt', 'signature'])
        ->and($queryParams['attempt'])->toBe((string)$attempt->id)
        ->and($queryParams['bootstrapToken'])->toBe($token);

    // Validate Cryptographic Signature
    $version = config('asap.active_key_version', 2);
    $activeKey = config("asap.keys.{$version}");
    $expectedSignature = hash_hmac('sha256', $token . '|' . $attempt->id, $activeKey);

    expect($queryParams['signature'])->toBe($expectedSignature);
});
