<?php

namespace Modules\ASAP\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Modules\ASAP\Http\Requests\SecurityEventRequest;
use Modules\ASAP\Http\Resources\SessionStateResource;
use Modules\ASAP\Services\SecurityEventService;
use Modules\ASAP\Exceptions\AsapException;

class SecurityEventController extends Controller
{
    public function __construct(
        protected SecurityEventService $securityEventService
    ) {}

    /**
     * Post an immediate security event.
     */
    public function report(SecurityEventRequest $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID') ?: Str::uuid()->toString();

        try {
            $session = $this->securityEventService->reportEvent(
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
