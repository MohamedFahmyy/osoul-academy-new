<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ASAP\Enums\DeviceStatus;
use Modules\ASAP\Enums\SessionStatus;
use Modules\ASAP\Enums\IncidentStatus;
use Modules\ASAP\Enums\PolicyAction;
use Modules\ASAP\Models\Device;
use Modules\ASAP\Models\ExamSession;
use Modules\ASAP\Models\Policy;
use Modules\ASAP\Models\PolicyRule;
use Modules\ASAP\Models\Telemetry;
use Modules\ASAP\Models\SecurityEvent;
use Modules\ASAP\Models\Incident;
use Modules\ASAP\Models\Evidence;
use Modules\ASAP\Repositories\DeviceRepositoryInterface;
use Modules\ASAP\Repositories\SessionRepositoryInterface;
use Modules\ASAP\Services\PolicyEngineInterface;
use Modules\Exam\Models\ExamCategory;
use Modules\Exam\Models\Exam;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Run the module seeders to populate policies
    $this->artisan('module:seed', ['module' => 'ASAP']);
});

it('verifies default policies and rules are correctly seeded', function () {
    $policy = Policy::where('is_default', true)->first();
    
    expect($policy)->not->toBeNull()
        ->and($policy->name)->toBe('Default ASAP Security Policy')
        ->and($policy->warning_threshold)->toBe(40.00)
        ->and($policy->pause_threshold)->toBe(60.00)
        ->and($policy->terminate_threshold)->toBe(85.00);

    // Verify rules count and content
    $rules = $policy->rules;
    expect($rules->count())->toBe(7);

    $obsRule = $rules->where('event_code', 'PROCESS_BLACKLIST_DETECTED')->first();
    expect($obsRule)->not->toBeNull()
        ->and($obsRule->weight)->toBe(60.00)
        ->and($obsRule->action)->toBe(PolicyAction::TERMINATE);
});

it('can resolve repository and service interfaces from container', function () {
    $deviceRepo = app(DeviceRepositoryInterface::class);
    $sessionRepo = app(SessionRepositoryInterface::class);
    $policyEngine = app(PolicyEngineInterface::class);

    expect($deviceRepo)->toBeInstanceOf(\Modules\ASAP\Repositories\EloquentDeviceRepository::class)
        ->and($sessionRepo)->toBeInstanceOf(\Modules\ASAP\Repositories\EloquentSessionRepository::class)
        ->and($policyEngine)->toBeInstanceOf(\Modules\ASAP\Services\DefaultPolicyEngine::class);
});

it('verifies ASAP domain relations and model lifecycle works', function () {
    // 1. Create Device
    $device = Device::create([
        'id' => \Illuminate\Support\Str::uuid()->toString(),
        'uuid' => 'dev_uuid_123456789',
        'name' => 'Student-PC',
        'operating_system' => 'Windows 11',
        'status' => DeviceStatus::VERIFIED,
        'hardware_hash' => hash('sha256', 'cpu:i7;ram:16GB'),
        'hardware_version' => '1.0.0',
        'registration_method' => 'uuid',
        'last_seen_at' => now(),
    ]);

    expect(Device::count())->toBe(1);

    // 2. Setup mock User & Exam
    $user = User::factory()->create(['role' => 'student']);
    $category = ExamCategory::create([
        'title' => 'Setup Category',
        'slug' => 'setup-category',
        'icon' => 'folder',
        'status' => true,
    ]);
    
    // Create instructor for the exam (since exam requires instructor)
    $instructorUser = User::factory()->create(['role' => 'instructor']);
    $instructor = \App\Models\Instructor::create([
        'user_id' => $instructorUser->id,
        'status' => 'approved',
        'designation' => 'Instructor',
        'skills' => ['programming'],
        'biography' => 'Bio',
        'resume' => 'Resume',
    ]);

    $exam = Exam::create([
        'title' => 'Domain Exam',
        'slug' => 'domain-exam',
        'status' => 'published',
        'pass_mark' => 20,
        'total_marks' => 40,
        'instructor_id' => $instructor->id,
        'exam_category_id' => $category->id,
    ]);

    $policy = Policy::where('is_default', true)->first();

    // 3. Create ExamSession linked to policy
    $session = ExamSession::create([
        'id' => \Illuminate\Support\Str::uuid()->toString(),
        'device_id' => $device->id,
        'user_id' => $user->id,
        'exam_id' => $exam->id,
        'policy_id' => $policy->id,
        'status' => SessionStatus::READY,
        'session_key_id' => 'key_id_xyz',
        'session_key_hash' => hash('sha256', 'mock_key'),
        'risk_score' => 10.00,
        'started_at' => now(),
    ]);

    expect(ExamSession::count())->toBe(1)
        ->and($session->device->name)->toBe('Student-PC')
        ->and($session->user->id)->toBe($user->id)
        ->and($session->exam->id)->toBe($exam->id)
        ->and($session->policy->name)->toBe('Default ASAP Security Policy');

    // 4. Save Telemetry
    $telemetry = Telemetry::create([
        'session_id' => $session->id,
        'telemetry_schema_version' => '1.0.0',
        'payload' => ['is_focused' => true, 'is_fullscreen' => true],
        'recorded_at' => now(),
    ]);

    expect($session->telemetries->count())->toBe(1)
        ->and($session->telemetries->first()->payload['is_focused'])->toBe(true);

    // 5. Save SecurityEvent
    $event = SecurityEvent::create([
        'id' => \Illuminate\Support\Str::uuid()->toString(),
        'session_id' => $session->id,
        'event_code' => 'WINDOW_UNFOCUS',
        'payload' => ['app' => 'Chrome'],
        'severity' => 'warning',
        'source' => 'client',
        'category' => 'window',
        'client_sequence' => 1,
        'processed_at' => now(),
        'policy_action' => 'warn',
        'risk_delta' => 10.00,
        'occurred_at' => now(),
    ]);

    expect($session->securityEvents->count())->toBe(1)
        ->and($session->securityEvents->first()->event_code)->toBe('WINDOW_UNFOCUS')
        ->and($session->securityEvents->first()->risk_delta)->toBe(10.00);

    // 6. Save Incident & Evidence
    $incident = Incident::create([
        'id' => \Illuminate\Support\Str::uuid()->toString(),
        'session_id' => $session->id,
        'status' => IncidentStatus::OPEN,
        'risk_score_snapshot' => 15.00,
    ]);

    $evidence = Evidence::create([
        'id' => \Illuminate\Support\Str::uuid()->toString(),
        'incident_id' => $incident->id,
        'telemetry_snapshot' => ['display_count' => 2],
        'event_snapshot' => ['event' => 'DISPLAY_ADDED'],
        'ip_address' => '127.0.0.1',
        'client_version' => '1.0.0',
        'policy_version' => 'v1.0',
        'risk_engine_version' => 'v1.0',
        'sdk_version' => 'v1.0',
        'os_version' => 'Windows 11',
        'decision' => 'PAUSE',
        'decision_source' => 'server_policy_engine',
        'engine_build' => 'asap_engine_1.0.0',
        'correlation_snapshot' => ['display_count' => 2],
        'decision_reason' => 'Multiple monitors connected.',
    ]);

    expect($session->incidents->count())->toBe(1)
        ->and($session->incidents->first()->evidence->ip_address)->toBe('127.0.0.1')
        ->and($session->incidents->first()->evidence->decision_reason)->toBe('Multiple monitors connected.')
        ->and($session->incidents->first()->evidence->decision_source)->toBe('server_policy_engine');
});
