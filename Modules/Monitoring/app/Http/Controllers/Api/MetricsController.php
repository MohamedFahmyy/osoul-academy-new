<?php

namespace Modules\Monitoring\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Modules\Monitoring\Services\MetricsCollectorService;

class MetricsController extends Controller
{
    public function __construct(
        protected MetricsCollectorService $metricsService
    ) {}

    /**
     * Expose metrics in Prometheus exporter format.
     */
    public function index(): Response
    {
        $payload = $this->metricsService->export();

        return response($payload, 200)
            ->header('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
    }
}
