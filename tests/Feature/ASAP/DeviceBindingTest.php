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

it('blocks session start or handshake if device is blacklisted/revoked/suspended', function () {
    // 1. Create a suspended/revoked device
    $suspendedDeviceUuid = 'desktop_client_' . $this->student->id;
    $device = Device::create([
        'id' => \Illuminate\Support\Str::uuid()->toString(),
        'uuid' => $suspendedDeviceUuid,
        'name' => 'PC',
        'operating_system' => 'Windows',
        'status' => DeviceStatus::SUSPENDED,
    ]);

    // 2. Put in Redis blacklist
    app(\Modules\ASAP\Services\ASAPCacheService::class)->blacklistDevice($suspendedDeviceUuid);

    // 3. Attempt starting a session via API -> expected 403
    Sanctum::actingAs($this->student);
    $payload = [
        'device_uuid' => $suspendedDeviceUuid,
        'device_name' => 'PC-Blacklisted',
        'operating_system' => 'Windows 11',
        'hardware_hash' => 'hash_suspended',
        'hardware_version' => '1.0.0',
        'exam_id' => $this->exam->id,
    ];

    $response = $this->postJson(route('api.asap.session.start'), $payload);
    $response->assertStatus(403);
});
