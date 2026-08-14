<?php

namespace Modules\ASAP\Database\Seeders;

use App\Models\User;
use App\Models\Instructor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Exam\Models\ExamCategory;
use Modules\Exam\Models\Exam;
use Modules\Exam\Models\ExamQuestion;
use Modules\ASAP\Models\Policy;
use Modules\ASAP\Models\PolicyRule;
use Modules\ASAP\Enums\PolicyAction;

class ASAPTestExamSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Ensure Default Category exists
        $category = ExamCategory::firstOrCreate(
            ['slug' => 'e2e-category'],
            [
                'title' => 'E2E Category',
                'icon' => 'folder',
                'status' => true,
            ]
        );

        // 2. Ensure Test Instructor exists
        $instructorUser = User::firstOrCreate(
            ['email' => 'asap-e2e-instructor@test.local'],
            [
                'name' => 'E2E Instructor',
                'password' => bcrypt('SecurePassword123'),
                'role' => 'instructor',
            ]
        );

        $instructor = Instructor::firstOrCreate(
            ['user_id' => $instructorUser->id],
            [
                'status' => 'approved',
                'designation' => 'E2E Instructor',
                'skills' => ['programming'],
                'biography' => 'E2E test instructor bio',
                'resume' => 'Resume content',
            ]
        );

        // 3. Ensure test exam is seeded dynamically (updates if exists)
        $exam = Exam::updateOrCreate(
            ['slug' => 'asap-security-test-exam'],
            [
                'title' => 'ASAP Security Test Exam',
                'status' => 'published',
                'pass_mark' => 20,
                'total_marks' => 30,
                'instructor_id' => $instructor->id,
                'exam_category_id' => $category->id,
                'max_attempts' => 5,
                'pricing_type' => 'free',
            ]
        );

        // Clean up previous E2E test user data
        $testStudent = User::where('email', 'asap-e2e-browser@test.local')->first();
        if (!$testStudent) {
            $testStudent = User::create([
                'name' => 'E2E Browser Candidate',
                'email' => 'asap-e2e-browser@test.local',
                'password' => bcrypt('SecurePassword123'),
                'role' => 'student',
                'email_verified_at' => now(),
            ]);
        } else {
            ExamAttempt::where('user_id', $testStudent->id)->where('exam_id', $exam->id)->delete();
            \Modules\ASAP\Models\ExamSession::where('user_id', $testStudent->id)->where('exam_id', $exam->id)->delete();
        }

        // 4. Ensure test questions exist
        ExamQuestion::where('exam_id', $exam->id)->delete();

        ExamQuestion::create([
            'exam_id' => $exam->id,
            'question_type' => 'subjective',
            'title' => 'E2E Security Handoff Test',
            'description' => 'Describe how asap:// custom protocol handoff works.',
            'marks' => 10,
        ]);

        ExamQuestion::create([
            'exam_id' => $exam->id,
            'question_type' => 'subjective',
            'title' => 'E2E Electron Heartbeats Test',
            'description' => 'Explain the cryptographic verification of heartbeats.',
            'marks' => 10,
        ]);

        ExamQuestion::create([
            'exam_id' => $exam->id,
            'question_type' => 'subjective',
            'title' => 'E2E Device Isolation Test',
            'description' => 'Detail how device UUID checks prevent replay attacks.',
            'marks' => 10,
        ]);

        // 5. Ensure Default Policy is seeded
        $policy = Policy::where('is_default', true)->first();
        if (!$policy) {
            $policy = Policy::create([
                'name' => 'Default ASAP Security Policy',
                'warning_threshold' => 40.00,
                'pause_threshold' => 60.00,
                'terminate_threshold' => 85.00,
                'is_default' => true,
            ]);

            $rules = [
                [
                    'event_code' => 'WINDOW_UNFOCUS',
                    'weight' => 10.00,
                    'cooldown_window' => 30,
                    'action' => PolicyAction::WARN,
                ],
                [
                    'event_code' => 'FULLSCREEN_EXIT',
                    'weight' => 20.00,
                    'cooldown_window' => 30,
                    'action' => PolicyAction::WARN,
                ],
                [
                    'event_code' => 'DISPLAY_ADDED',
                    'weight' => 30.00,
                    'cooldown_window' => 0,
                    'action' => PolicyAction::PAUSE,
                ],
                [
                    'event_code' => 'PROCESS_BLACKLIST_DETECTED',
                    'weight' => 60.00,
                    'cooldown_window' => 0,
                    'action' => PolicyAction::TERMINATE,
                ],
                [
                    'event_code' => 'HEARTBEAT_TIMEOUT',
                    'weight' => 40.00,
                    'cooldown_window' => 0,
                    'action' => PolicyAction::PAUSE,
                ],
                [
                    'event_code' => 'DEVTOOLS_DETECTED',
                    'weight' => 50.00,
                    'cooldown_window' => 0,
                    'action' => PolicyAction::PAUSE,
                ],
                [
                    'event_code' => 'VM_DETECTED',
                    'weight' => 15.00,
                    'cooldown_window' => 0,
                    'action' => PolicyAction::WARN,
                ]
            ];

            foreach ($rules as $rule) {
                PolicyRule::create(array_merge($rule, [
                    'policy_id' => $policy->id,
                ]));
            }
        }
    }
}
