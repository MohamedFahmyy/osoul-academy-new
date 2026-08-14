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

it('rejects duplicate concurrent active exam sessions for the same student-exam (409 Conflict)', function () {
    // 1. Start the first session (e.g. by hitting the take page)
    $response1 = $this->get(route('exam-attempts.take', $this->attempt->id));
    $response1->assertStatus(200);

    // 2. Attempt starting a concurrent session via API -> expected 409 Conflict
    Sanctum::actingAs($this->student);
    $payload = [
        'device_uuid' => 'second_device_uuid_7777',
        'device_name' => 'PC-Secondary',
        'operating_system' => 'Windows 11',
        'hardware_hash' => 'hash_secondary',
        'hardware_version' => '1.0.0',
        'exam_id' => $this->exam->id,
    ];

    $response2 = $this->postJson(route('api.asap.session.start'), $payload);
    $response2->assertStatus(409)
        ->assertJsonPath('error_code', 'ASAP-1007');
});
