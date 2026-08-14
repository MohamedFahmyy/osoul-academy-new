<?php

namespace Modules\Exam\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Exam\Http\Requests\ExamAttemptRequest;
use Modules\Exam\Models\Exam;
use Modules\Exam\Models\ExamAttempt;
use Modules\Exam\Services\ExamAttemptService;
use Modules\Exam\Services\ExamEnrollmentService;

class ExamAttemptController extends Controller
{
    public function __construct(
        protected ExamAttemptService $examAttempt,
        protected ExamEnrollmentService $enrollmentService
    ) {}

    /**
     * Display a listing of exams
     */
    public function index(Request $request, string $exam_id): Response
    {
        $attempts = $this->examAttempt->getExamAttempts(array_merge($request->all(), [
            'exam_id' => $exam_id,
            'paginate' => true,
            'relations' => ['user:id,name,email'],
        ]));

        return Inertia::render('Exam/dashboard/exams/attempts', [
            'exam_id' => $exam_id,
            'attempts' => $attempts,
        ]);
    }

    /**
     * Show single exam details (public page)
     */
    public function review(string $exam_id, string $id): Response
    {
        $attempt = $this->examAttempt->getExamAttempt($id, [
            'relations' => [
                'attempt_answers:id,exam_attempt_id,exam_question_id,answer_data,is_correct,marks_obtained',
                'attempt_answers.exam_question:id,title,description,marks,question_type,options',
                'attempt_answers.exam_question.question_options:id,exam_question_id,option_text,is_correct',
            ],
        ]);

        return Inertia::render('Exam/dashboard/exams/review', [
            'exam_id' => $exam_id,
            'attempt' => $attempt,
        ]);
    }

    /**
     * Start a new exam attempt
     */
    public function start(ExamAttemptRequest $request, Exam $exam)
    {
        $user = Auth::user();
        $attempt = $this->examAttempt->startAttempt($user, $exam);

        if (! $attempt) {
            return back()->with('error', 'Unable to start exam. You may have reached the maximum number of attempts.');
        }

        return redirect()
            ->route('exam-attempts.take', $attempt->id)
            ->with('success', 'Exam started. Good luck!');
    }

    /**
     * Take the exam (show questions)
     */
    public function take(ExamAttempt $attempt)
    {
        $user = Auth::user();

        // 1. Authorize student
        abort_if($attempt->user_id !== $user->id, 403);

        // 2. Prevent re-opening completed or abandoned exams
        if ($attempt->status === 'completed' || $attempt->status === 'abandoned') {
            return redirect()
                ->route('student.exam.show', [
                    'id' => $attempt->exam_id,
                    'tab' => 'attempts',
                ])
                ->with('error', 'This exam attempt has already been finished.');
        }

        $attempt->load(['exam.questions.question_options']);
        
        // 3. Resolve or register the default desktop device for this user
        $deviceUuid = 'desktop_client_' . $user->id;
        $device = \Modules\ASAP\Models\Device::firstOrCreate(
            ['uuid' => $deviceUuid],
            [
                'id' => \Illuminate\Support\Str::uuid()->toString(),
                'name' => 'Secure Desktop Client',
                'operating_system' => 'Windows 10',
                'status' => \Modules\ASAP\Enums\DeviceStatus::VERIFIED,
            ]
        );

        // 4. Fetch the default Policy
        $policy = \Modules\ASAP\Models\Policy::where('is_default', true)->first();
        if (!$policy) {
            $policy = \Modules\ASAP\Models\Policy::first();
        }

        // 5. Find or create an active exam session for this user and exam
        $session = \Modules\ASAP\Models\ExamSession::where('user_id', $user->id)
            ->where('exam_id', $attempt->exam_id)
            ->whereIn('status', [
                \Modules\ASAP\Enums\SessionStatus::CREATED->value,
                \Modules\ASAP\Enums\SessionStatus::RUNNING->value,
                \Modules\ASAP\Enums\SessionStatus::READY->value,
                \Modules\ASAP\Enums\SessionStatus::WARNING->value,
                \Modules\ASAP\Enums\SessionStatus::PAUSED->value,
                \Modules\ASAP\Enums\SessionStatus::RESUMED->value
            ])
            ->first();

        if (!$session) {
            $keyService = app(\Modules\ASAP\Services\SessionKeyService::class);
            $keys = $keyService->generateKey(new \Modules\ASAP\Models\ExamSession());

            $session = \Modules\ASAP\Models\ExamSession::create([
                'id' => \Illuminate\Support\Str::uuid()->toString(),
                'device_id' => $device->id,
                'user_id' => $user->id,
                'exam_id' => $attempt->exam_id,
                'policy_id' => $policy->id,
                'status' => \Modules\ASAP\Enums\SessionStatus::CREATED->value,
                'session_key_id' => $keys['session_key_id'],
                'session_key_hash' => $keys['session_key_hash'],
                'session_key_encrypted' => $keys['session_key_encrypted'],
                'expires_at' => now()->addHours(4),
                'risk_score' => 0.00,
            ]);
        }

        // 6. Check heartbeat timeout on existing session
        app(\Modules\ASAP\Services\SessionService::class)->checkHeartbeatTimeout($session);
        $session->refresh();

        // 7. Generate a bootstrap token (60 minutes for local/testing convenience)
        $bootstrapToken = \Modules\ASAP\Services\BootstrapToken::generate($session->id, $attempt->id, 60);
        $session->update([
            'bootstrap_token' => hash('sha256', $bootstrapToken),
            'bootstrap_token_expires_at' => now()->addMinutes(60),
        ]);

        // 8. Construct ASAP custom protocol URL
        $version = config('asap.active_key_version', 2);
        $activeKey = config("asap.keys.{$version}");
        if (!$activeKey) {
            throw new \Exception("Signing key for version {$version} not found.");
        }
        $launchUrl = "asap://open?bootstrapToken=" . urlencode($bootstrapToken)
            . "&attempt=" . $attempt->id
            . "&signature=" . hash_hmac('sha256', $bootstrapToken . '|' . $attempt->id, $activeKey);

        return Inertia::render('student/exam/attempt', [
            'attempt' => $attempt,
            'bootstrapToken' => $bootstrapToken,
            'asapSessionId' => $session->id,
            'asapProtocolUrl' => $launchUrl,
        ]);
    }

    /**
     * Submit exam answers
     */
    public function submit(ExamAttemptRequest $request, ExamAttempt $attempt)
    {
        abort_if($attempt->user_id !== Auth::id(), 403);

        // Check if attempt is in progress
        if ($attempt->status !== 'in_progress') {
            return back()->with('error', 'This attempt has already been submitted.');
        }

        $answers = $request->input('answers', []);
        $attempt = $this->examAttempt->submitAttempt($attempt, $answers);

        // Find active ASAP session and set completed
        $session = \Modules\ASAP\Models\ExamSession::where('user_id', $attempt->user_id)
            ->where('exam_id', $attempt->exam_id)
            ->whereIn('status', [
                \Modules\ASAP\Enums\SessionStatus::CREATED->value,
                \Modules\ASAP\Enums\SessionStatus::RUNNING->value,
                \Modules\ASAP\Enums\SessionStatus::READY->value,
                \Modules\ASAP\Enums\SessionStatus::WARNING->value,
                \Modules\ASAP\Enums\SessionStatus::PAUSED->value,
                \Modules\ASAP\Enums\SessionStatus::RESUMED->value
            ])
            ->first();

        if ($session) {
            $session->update([
                'status' => \Modules\ASAP\Enums\SessionStatus::COMPLETED->value,
                'ended_at' => now(),
            ]);
            app(\Modules\ASAP\Services\ASAPCacheService::class)->forgetSessionState($session->id);
        }

        return redirect()
            ->route('student.exam.show', [
                'id' => $attempt->exam_id,
                'tab' => 'attempts',
                'attempt' => $attempt->id,
            ])
            ->with('success', 'Exam submitted successfully!');
    }

    /**
     * Abandon an in-progress attempt
     */
    public function abandon(ExamAttempt $attempt)
    {
        abort_if($attempt->user_id !== Auth::id(), 403);

        if ($attempt->status !== 'in_progress') {
            return back()->with('error', 'This attempt is not in progress.');
        }

        $this->examAttempt->abandonAttempt($attempt);

        // Find active ASAP session and set terminated
        $session = \Modules\ASAP\Models\ExamSession::where('user_id', $attempt->user_id)
            ->where('exam_id', $attempt->exam_id)
            ->whereIn('status', [
                \Modules\ASAP\Enums\SessionStatus::CREATED->value,
                \Modules\ASAP\Enums\SessionStatus::RUNNING->value,
                \Modules\ASAP\Enums\SessionStatus::READY->value,
                \Modules\ASAP\Enums\SessionStatus::WARNING->value,
                \Modules\ASAP\Enums\SessionStatus::PAUSED->value,
                \Modules\ASAP\Enums\SessionStatus::RESUMED->value
            ])
            ->first();

        if ($session) {
            $session->update([
                'status' => \Modules\ASAP\Enums\SessionStatus::TERMINATED->value,
                'ended_at' => now(),
            ]);
            app(\Modules\ASAP\Services\ASAPCacheService::class)->forgetSessionState($session->id);
        }

        return redirect()
            ->route('exams.edit', [
                'exam' => $attempt->exam_id,
                'tab' => 'attempts',
                'attempt' => $attempt->id,
            ])
            ->with('info', 'Exam attempt abandoned.');
    }

    /**
     * Grade exam attempt (Admin/Instructor only)
     */
    public function grade(Request $request, ExamAttempt $attempt)
    {
        // Validate manual grades
        $manualGrades = $request->input('manual_grades', []);

        $this->examAttempt->reviewAttempt($attempt, $manualGrades);

        return redirect(route('exam-attempts.index', $attempt->exam_id))->with('success', 'Exam attempt graded successfully!');
    }
}
