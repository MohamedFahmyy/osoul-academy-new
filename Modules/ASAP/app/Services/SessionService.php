<?php

namespace Modules\ASAP\Services;

use App\Models\User;
use Illuminate\Support\Str;
use Modules\ASAP\Enums\DeviceStatus;
use Modules\ASAP\Enums\SessionStatus;
use Modules\ASAP\Exceptions\AsapException;
use Modules\ASAP\Events\SessionCreated;
use Modules\ASAP\Models\Device;
use Modules\ASAP\Models\ExamSession;
use Modules\ASAP\Models\Policy;
use Modules\ASAP\Repositories\DeviceRepositoryInterface;
use Modules\ASAP\Repositories\SessionRepositoryInterface;

class SessionService
{
    public function __construct(
        protected DeviceRepositoryInterface $deviceRepo,
        protected SessionRepositoryInterface $sessionRepo,
        protected SessionKeyService $keyService,
        protected ASAPCacheService $cacheService,
        protected AuditLogger $auditLogger
    ) {}

    /**
     * Start a new secure exam session.
     *
     * @throws AsapException
     */
    public function startSession(User $user, array $data, string $correlationId): ExamSession
    {
        // 1. Fast boundary check via Redis device blacklist cache
        if ($this->cacheService->isDeviceBlacklisted($data['device_uuid'])) {
            throw new AsapException(
                'ASAP-1002',
                'Device is blacklisted (suspended or revoked).',
                403
            );
        }

        // Resolve or register the device
        $device = $this->deviceRepo->findByUuid($data['device_uuid']);
        if (!$device) {
            $device = $this->deviceRepo->create([
                'id' => Str::uuid()->toString(),
                'uuid' => $data['device_uuid'],
                'name' => $data['device_name'],
                'operating_system' => $data['operating_system'],
                'status' => DeviceStatus::PENDING,
                'hardware_hash' => $data['hardware_hash'],
                'hardware_version' => $data['hardware_version'],
                'registration_method' => 'uuid',
                'last_seen_at' => now(),
            ]);
        } else {
            // Update device metrics
            $this->deviceRepo->update($device->id, [
                'name' => $data['device_name'],
                'operating_system' => $data['operating_system'],
                'hardware_hash' => $data['hardware_hash'],
                'hardware_version' => $data['hardware_version'],
                'last_seen_at' => now(),
            ]);

            // Check trust boundary
            if ($device->status === DeviceStatus::SUSPENDED || $device->status === DeviceStatus::REVOKED) {
                $this->cacheService->blacklistDevice($device->uuid);
                throw new AsapException(
                    'ASAP-1002',
                    'Device is not trusted (suspended or revoked).',
                    403
                );
            }
        }

        // 2. Fetch the default Policy
        $policy = Policy::where('is_default', true)->first();
        if (!$policy) {
            throw new AsapException(
                'ASAP-1005',
                'Default security policy is not configured.',
                500
            );
        }

        // 3. Prevent duplicate active session for the same user-exam
        $activeSession = ExamSession::where('user_id', $user->id)
            ->where('exam_id', $data['exam_id'])
            ->whereIn('status', [
                SessionStatus::CREATED->value,
                SessionStatus::RUNNING->value,
                SessionStatus::READY->value,
                SessionStatus::WARNING->value,
                SessionStatus::PAUSED->value,
                SessionStatus::RESUMED->value
            ])
            ->first();

        if ($activeSession) {
            throw new AsapException(
                'ASAP-1007',
                'An active exam session already exists for this candidate.',
                409
            );
        }

        // 4. Ephemeral session key generation using key service
        $keys = $this->keyService->generateKey(new ExamSession());

        // 5. Create Exam Session
        $session = $this->sessionRepo->create([
            'id' => Str::uuid()->toString(),
            'device_id' => $device->id,
            'user_id' => $user->id,
            'exam_id' => $data['exam_id'],
            'policy_id' => $policy->id,
            'status' => SessionStatus::CREATED->value,
            'session_key_id' => $keys['session_key_id'],
            'session_key_hash' => $keys['session_key_hash'],
            'session_key_encrypted' => $keys['session_key_encrypted'],
            'expires_at' => now()->addHours(4),
            'risk_score' => 0.00,
        ]);

        // Attach ephemeral key dynamically for the controller layer
        $session->raw_key = $keys['raw_key'];

        // 6. Cache state to Redis
        $this->cacheService->putSessionState($session);

        // 7. Audit Logging
        $this->auditLogger->logSessionStart($session, $correlationId);

        // Increment Prometheus metrics if Monitoring module is loaded
        if (app()->bound(\Modules\Monitoring\Services\MetricsCollectorService::class)) {
            app(\Modules\Monitoring\Services\MetricsCollectorService::class)->increment('asap_active_sessions');
        }

        // 8. Dispatch domain event
        event(new SessionCreated($session, $correlationId));

        return $session;
    }

    /**
     * Authenticate and verify the request's HMAC signature.
     *
     * @throws AsapException
     */
    public function verifySessionSignature(string $sessionId, string $keyId, string $signature, array $payload): ExamSession
    {
        $session = $this->sessionRepo->findById($sessionId);
        if (!$session) {
            throw new AsapException('ASAP-1001', 'Session not found.', 401);
        }

        if ($this->keyService->isExpired($session)) {
            throw new AsapException('ASAP-1001', 'Session has expired.', 401);
        }

        if (in_array($session->status, [SessionStatus::COMPLETED, SessionStatus::TERMINATED])) {
            throw new AsapException('ASAP-1008', 'Session is no longer active.', 400);
        }

        if ($session->session_key_id !== $keyId) {
            throw new AsapException('ASAP-1004', 'Invalid session key ID.', 400);
        }

        // Decrypt the raw session key
        try {
            $rawKey = $this->keyService->decryptKey($session);
        } catch (\Exception $e) {
            throw new AsapException('ASAP-1004', 'Failed to decrypt session key.', 500);
        }

        // Verify HMAC signature
        if (!$this->keyService->verifySignature($rawKey, $payload, $signature)) {
            if (app()->bound(\Modules\Monitoring\Services\MetricsCollectorService::class)) {
                app(\Modules\Monitoring\Services\MetricsCollectorService::class)->increment('asap_heartbeat_signature_failures_total');
            }
            throw new AsapException('ASAP-1004', 'Invalid HMAC signature.', 400);
        }

        return $session;
    }

    /**
     * Secure handshake to retrieve the ephemeral session keys.
     *
     * @throws AsapException
     */
    public function handshakeSession(User $user, string $sessionId): ExamSession
    {
        $session = $this->sessionRepo->findById($sessionId);
        if (!$session) {
            throw new AsapException('ASAP-1001', 'Session not found.', 404);
        }

        if ($session->user_id !== $user->id) {
            throw new AsapException('ASAP-1001', 'Access denied to this session.', 403);
        }

        if ($this->keyService->isExpired($session)) {
            throw new AsapException('ASAP-1001', 'Session has expired.', 401);
        }

        try {
            $session->raw_key = $this->keyService->decryptKey($session);
        } catch (\Exception $e) {
            throw new AsapException('ASAP-1004', 'Failed to decrypt session key.', 500);
        }

        return $session;
    }

    /**
     * Check if the session heartbeat has timed out, logging an event and updating status if so.
     */
    public function checkHeartbeatTimeout(ExamSession $session): void
    {
        if ($session->status !== SessionStatus::RUNNING && $session->status !== SessionStatus::READY) {
            return;
        }

        // Get latest telemetry recorded time
        $latestTelemetry = $session->telemetries()->latest('recorded_at')->first();
        $lastActive = $latestTelemetry ? $latestTelemetry->recorded_at : $session->started_at ?? $session->created_at;

        if ($lastActive && $lastActive->diffInSeconds(now()) > 60) {
            // Heartbeat has timed out!
            $eventUuid = \Illuminate\Support\Str::uuid()->toString();
            $event = \Modules\ASAP\Models\SecurityEvent::create([
                'id' => $eventUuid,
                'session_id' => $session->id,
                'event_code' => 'HEARTBEAT_TIMEOUT',
                'payload' => ['last_active' => $lastActive->toIso8601String()],
                'severity' => 'critical',
                'source' => 'server',
                'category' => 'system',
                'occurred_at' => now(),
            ]);

            $policyEngine = app(PolicyEngineInterface::class);
            $policyEngine->ingestEvent($session, $event);
        }
    }
}
