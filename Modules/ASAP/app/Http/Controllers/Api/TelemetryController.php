<?php

namespace Modules\ASAP\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Modules\ASAP\Http\Requests\TelemetryHeartbeatRequest;
use Modules\ASAP\Http\Resources\SessionStateResource;
use Modules\ASAP\Services\TelemetryService;
use Modules\ASAP\Exceptions\AsapException;

class TelemetryController extends Controller
{
    public function __construct(
        protected TelemetryService $telemetryService
    ) {}

    /**
     * Post heartbeat client telemetry.
     */
    public function heartbeat(TelemetryHeartbeatRequest $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID') ?: Str::uuid()->toString();

        try {
            $session = $this->telemetryService->processHeartbeat(
                $request->validated(),
                $correlationId
            );

            return response()->json([
                'status' => 'success',
                'session' => new SessionStateResource($session),
            ], 200)->header('X-Correlation-ID', $correlationId);

        } catch (AsapException $e) {
            return response()->json([
                'status' => 'error',
                'error_code' => $e->errorCode,
                'message' => $e->getMessage()
            ], $e->statusCode)->header('X-Correlation-ID', $correlationId);
        }
    }
}
