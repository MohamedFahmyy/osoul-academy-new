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

    // Create Candidate A
    $this->studentA = User::factory()->create(['role' => 'student']);
    // Create Candidate B
    $this->studentB = User::factory()->create(['role' => 'student']);

    // Setup Category, Instructor & Exam
    $this->category = ExamCategory::create([
        'title' => 'Category',
        'slug' => 'cat-slug',
        'icon' => 'folder',
        'status' => true,
    ]);

    $instructorUser = User::factory()->create(['role' => 'instructor']);
    $instructor = \App\Models\Instructor::create([
        'user_id' => $instructorUser->id,
        'status' => 'approved',
        'designation' => 'Instructor',
        'skills' => ['programming'],
        'biography' => 'Bio',
        'resume' => 'Resume',
    ]);

    $this->exam = Exam::create([
        'title' => 'Secure Assessment Exam',
        'slug' => 'secure-assessment-exam',
        'status' => 'published',
        'pass_mark' => 20,
        'total_marks' => 100,
        'instructor_id' => $instructor->id,
        'exam_category_id' => $this->category->id,
    ]);

    $this->question = \Modules\Exam\Models\ExamQuestion::create([
        'exam_id' => $this->exam->id,
        'question_type' => 'subjective',
        'title' => 'Subjective Question',
        'description' => 'Describe PHP.',
        'marks' => 10,
    ]);
});

it('blocks Student A from taking Student B\'s attempt', function () {
    // 1. Start attempt for Student B
    $attempt = ExamAttempt::create([
        'user_id' => $this->studentB->id,
        'exam_id' => $this->exam->id,
        'attempt_number' => 1,
        'status' => 'in_progress',
        'start_time' => now(),
    ]);

    // 2. Authenticate Student A
    $this->actingAs($this->studentA);

    // 3. Attempt to load take page of Student B's attempt -> expected 403
    $response = $this->get(route('exam-attempts.take', $attempt->id));
    $response->assertStatus(403);
});

it('blocks Student A from submitting Student B\'s attempt', function () {
    $attempt = ExamAttempt::create([
        'user_id' => $this->studentB->id,
        'exam_id' => $this->exam->id,
        'attempt_number' => 1,
        'status' => 'in_progress',
        'start_time' => now(),
    ]);

    $this->actingAs($this->studentA);

    $response = $this->postJson(route('exam-attempts.submit', $attempt->id), [
        'exam_attempt_id' => $attempt->id,
        'answers' => [
            [
                'exam_question_id' => $this->question->id,
                'answer_data' => 'This is a sample answer.',
            ]
        ],
    ]);
    $response->assertStatus(403);
});

it('blocks Student A from abandoning Student B\'s attempt', function () {
    $attempt = ExamAttempt::create([
        'user_id' => $this->studentB->id,
        'exam_id' => $this->exam->id,
        'attempt_number' => 1,
        'status' => 'in_progress',
        'start_time' => now(),
    ]);

    $this->actingAs($this->studentA);

    $response = $this->post(route('exam-attempts.abandon', $attempt->id));
    $response->assertStatus(403);
});

it('allows Student B to access their own attempt routes', function () {
    $attempt = ExamAttempt::create([
        'user_id' => $this->studentB->id,
        'exam_id' => $this->exam->id,
        'attempt_number' => 1,
        'status' => 'in_progress',
        'start_time' => now(),
    ]);

    $this->actingAs($this->studentB);

    $response = $this->get(route('exam-attempts.take', $attempt->id));
    $response->assertStatus(200);
});
