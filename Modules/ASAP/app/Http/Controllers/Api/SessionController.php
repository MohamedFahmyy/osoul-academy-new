<?php

namespace Modules\ASAP\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Modules\ASAP\Http\Requests\SessionStartRequest;
use Modules\ASAP\Http\Resources\SessionStateResource;
use Modules\ASAP\Services\SessionService;
use Modules\ASAP\Exceptions\AsapException;

class SessionController extends Controller
{
    public function __construct(
        protected SessionService $sessionService
    ) {}

    /**
     * Start a secure exam session.
     */
    public function start(SessionStartRequest $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID') ?: Str::uuid()->toString();

        try {
            // Verify authenticated user
            $user = Auth::user();
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'error_code' => 'ASAP-1001',
                    'message' => 'Unauthorized. Session requires an authenticated user.'
                ], 401)->header('X-Correlation-ID', $correlationId);
            }

            $session = $this->sessionService->startSession(
                $user,
                $request->validated(),
                $correlationId
            );

            return response()->json([
                'status' => 'success',
                'session' => new SessionStateResource($session),
                'credentials' => [
                    'session_key_id' => $session->session_key_id,
                    'session_key' => $session->raw_key,
                ]
            ], 201)->header('X-Correlation-ID', $correlationId);

        } catch (AsapException $e) {
            return response()->json([
                'status' => 'error',
                'error_code' => $e->errorCode,
                'message' => $e->getMessage()
            ], $e->statusCode)->header('X-Correlation-ID', $correlationId);
        }
    }

    /**
     * Handshake to retrieve the ephemeral session keys securely.
     */
    public function handshake(\Illuminate\Http\Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID') ?: Str::uuid()->toString();

        try {
            $token = $request->bearerToken() ?: $request->input('bootstrap_token');

            if (!$token) {
                return response()->json([
                    'status' => 'error',
                    'error_code' => 'ASAP-1001',
                    'message' => 'Missing bootstrap token.'
                ], 400)->header('X-Correlation-ID', $correlationId);
            }

            // Decode and verify the bootstrap token signature
            $decoded = null;
            $sessionId = null;
            if (str_contains($token, '.')) {
                $decoded = \Modules\ASAP\Services\BootstrapToken::decode($token);
                if (!$decoded) {
                    return response()->json([
                        'status' => 'error',
                        'error_code' => 'ASAP-1001',
                        'message' => 'Invalid bootstrap token signature.'
                    ], 401)->header('X-Correlation-ID', $correlationId);
                }
                $sessionId = $decoded->sid;
            } else {
                $sessionId = $request->input('session_id');
            }

            if (!$sessionId) {
                return response()->json([
                    'status' => 'error',
                    'error_code' => 'ASAP-1001',
                    'message' => 'Missing session_id context.'
                ], 400)->header('X-Correlation-ID', $correlationId);
            }

            $session = \Modules\ASAP\Models\ExamSession::where('id', $sessionId)->first();
            if (!$session) {
                return response()->json([
                    'status' => 'error',
                    'error_code' => 'ASAP-1001',
                    'message' => 'Session not found.'
                ], 404)->header('X-Correlation-ID', $correlationId);
            }

            // Verify device binding (hijack check)
            $deviceUuid = $request->input('device_uuid');
            if ($deviceUuid && $session->device && $session->device->uuid !== $deviceUuid) {
                return response()->json([
                    'status' => 'error',
                    'error_code' => 'ASAP-1002',
                    'message' => 'Device binding violation. This session is bound to a different device.'
                ], 403)->header('X-Correlation-ID', $correlationId);
            }

            // Verify token expiration
            if ($decoded) {
                if ($decoded->expires_at->isPast()) {
                    return response()->json([
                        'status' => 'error',
                        'error_code' => 'ASAP-1001',
                        'message' => 'Bootstrap token expired.'
                    ], 401)->header('X-Correlation-ID', $correlationId);
                }
            } else {
                if ($session->bootstrap_token_expires_at && $session->bootstrap_token_expires_at->isPast()) {
                    return response()->json([
                        'status' => 'error',
                        'error_code' => 'ASAP-1001',
                        'message' => 'Bootstrap token expired.'
                    ], 401)->header('X-Correlation-ID', $correlationId);
                }
            }

            // Verify bootstrap token hash in database (prevents reuse)
            if (!$session->bootstrap_token || 
                !hash_equals($session->bootstrap_token, hash('sha256', $token))) {
                return response()->json([
                    'status' => 'error',
                    'error_code' => 'ASAP-1001',
                    'message' => 'Invalid or expired bootstrap token.'
                ], 401)->header('X-Correlation-ID', $correlationId);
            }

            // Verify attempt and signature consistency if attempt_id is provided
            $attemptId = $request->input('attempt_id');
            if ($decoded && $attemptId !== null) {
                $attemptId = (int)$attemptId;
                if ($attemptId !== (int)$decoded->aid) {
                    return response()->json([
                        'status' => 'error',
                        'error_code' => 'ASAP-1001',
                        'message' => 'Attempt ID mismatch between request and token.'
                    ], 400)->header('X-Correlation-ID', $correlationId);
                }

                $attempt = \Modules\Exam\Models\ExamAttempt::find($attemptId);
                if (!$attempt) {
                    return response()->json([
                        'status' => 'error',
                        'error_code' => 'ASAP-1001',
                        'message' => 'Exam attempt not found.'
                    ], 404)->header('X-Correlation-ID', $correlationId);
                }

                if ($attempt->user_id !== $session->user_id || $attempt->exam_id !== $session->exam_id) {
                    return response()->json([
                        'status' => 'error',
                        'error_code' => 'ASAP-1001',
                        'message' => 'Attempt does not match session user or exam.'
                    ], 403)->header('X-Correlation-ID', $correlationId);
                }

                if ($attempt->status !== 'in_progress') {
                    return response()->json([
                        'status' => 'error',
                        'error_code' => 'ASAP-1001',
                        'message' => 'Exam attempt is not in progress.'
                    ], 400)->header('X-Correlation-ID', $correlationId);
                }

                // Verify launch URL HMAC signature
                $signature = $request->input('signature');
                if ($signature) {
                    $version = $decoded->kid;
                    $activeKey = config("asap.keys.{$version}");
                    if (!$activeKey) {
                        return response()->json([
                            'status' => 'error',
                            'error_code' => 'ASAP-1001',
                            'message' => "Signing key version {$version} not found in configuration."
                        ], 500)->header('X-Correlation-ID', $correlationId);
                    }
                    $expectedSignature = hash_hmac('sha256', $token . '|' . $attemptId, $activeKey);
                    if (!hash_equals($expectedSignature, $signature)) {
                        return response()->json([
                            'status' => 'error',
                            'error_code' => 'ASAP-1001',
                            'message' => 'Invalid launch signature.'
                        ], 400)->header('X-Correlation-ID', $correlationId);
                    }
                }
            }

            // Resolve target URL
            $targetUrl = null;
            if ($decoded) {
                $targetUrl = route('exam-attempts.take', $decoded->aid);
            } else {
                $targetUrl = $request->input('url');
            }

            // Decrypt key
            $keyService = app(\Modules\ASAP\Services\SessionKeyService::class);
            $rawKey = $keyService->decryptKey($session);

            // Consume bootstrap token & update status to ready
            $session->update([
                'bootstrap_token' => null,
                'bootstrap_token_expires_at' => null,
                'status' => \Modules\ASAP\Enums\SessionStatus::READY->value,
            ]);

            return response()->json([
                'status' => 'success',
                'session_id' => $session->id,
                'session_key_id' => $session->session_key_id,
                'session_key' => $rawKey,
                'target_url' => $targetUrl,
            ], 200)->header('X-Correlation-ID', $correlationId);

        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'error_code' => 'ASAP-1001',
                'message' => 'Handshake verification failed: ' . $e->getMessage()
            ], 500)->header('X-Correlation-ID', $correlationId);
        }
    }

    /**
     * Get the status/state of an active exam session.
     */
    public function show(\Illuminate\Http\Request $request, string $id): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID') ?: Str::uuid()->toString();

        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'error_code' => 'ASAP-1001',
                    'message' => 'Unauthorized.'
                ], 401)->header('X-Correlation-ID', $correlationId);
            }

            $session = \Modules\ASAP\Models\ExamSession::where('id', $id)->first();
            if (!$session) {
                return response()->json([
                    'status' => 'error',
                    'error_code' => 'ASAP-1001',
                    'message' => 'Session not found.'
                ], 404)->header('X-Correlation-ID', $correlationId);
            }

            if ($session->user_id !== $user->id) {
                return response()->json([
                    'status' => 'error',
                    'error_code' => 'ASAP-1001',
                    'message' => 'Access denied.'
                ], 403)->header('X-Correlation-ID', $correlationId);
            }

            return response()->json([
                'status' => 'success',
                'session' => new SessionStateResource($session),
            ], 200)->header('X-Correlation-ID', $correlationId);

        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'error_code' => 'ASAP-1001',
                'message' => $e->getMessage()
            ], 500)->header('X-Correlation-ID', $correlationId);
        }
    }
}
