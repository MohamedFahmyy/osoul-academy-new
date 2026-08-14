<?php

namespace Modules\Monitoring\Services;

use Illuminate\Support\Facades\Cache;

class MetricsCollectorService
{
    /**
     * Get the cache store instance.
     */
    protected function getStore()
    {
        try {
            if (!class_exists('Redis')) {
                return Cache::store();
            }
            return Cache::store('redis');
        } catch (\Throwable $e) {
            return Cache::store();
        }
    }

    /**
     * Increment a metric counter.
     */
    public function increment(string $metricName, int $value = 1): void
    {
        $store = $this->getStore();
        $key = "metrics:{$metricName}";
        if (!$store->has($key)) {
            $store->put($key, 0, 86400 * 30);
        }
        $store->increment($key, $value);
    }

    /**
     * Set a metric gauge value.
     */
    public function setGauge(string $metricName, float $value): void
    {
        $store = $this->getStore();
        $key = "metrics:{$metricName}";
        $store->put($key, $value, 86400 * 30);
    }

    /**
     * Retrieve a metric value.
     */
    public function getMetric(string $metricName, float $default = 0.0): float
    {
        $store = $this->getStore();
        return (float) ($store->get("metrics:{$metricName}") ?? $default);
    }

    /**
     * Export metrics in Prometheus text format.
     */
    public function export(): string
    {
        $metrics = [
            'asap_heartbeat_total' => [
                'type' => 'counter',
                'help' => 'Total number of telemetry heartbeat ticks received.',
                'value' => $this->getMetric('asap_heartbeat_total'),
            ],
            'asap_heartbeat_signature_failures_total' => [
                'type' => 'counter',
                'help' => 'Total number of heartbeat requests with invalid signature.',
                'value' => $this->getMetric('asap_heartbeat_signature_failures_total'),
            ],
            'asap_policy_warn_total' => [
                'type' => 'counter',
                'help' => 'Total warning decisions triggered.',
                'value' => $this->getMetric('asap_policy_warn_total'),
            ],
            'asap_policy_pause_total' => [
                'type' => 'counter',
                'help' => 'Total pause decisions triggered.',
                'value' => $this->getMetric('asap_policy_pause_total'),
            ],
            'asap_policy_terminate_total' => [
                'type' => 'counter',
                'help' => 'Total termination decisions triggered.',
                'value' => $this->getMetric('asap_policy_terminate_total'),
            ],
            'asap_active_sessions' => [
                'type' => 'gauge',
                'help' => 'Current number of active security exam sessions.',
                'value' => $this->getMetric('asap_active_sessions'),
            ],
            'asap_telemetry_latency_seconds_sum' => [
                'type' => 'counter',
                'help' => 'Sum of telemetry latency processing times in seconds.',
                'value' => $this->getMetric('asap_telemetry_latency_seconds_sum'),
            ],
            'asap_policy_decision_duration_seconds_sum' => [
                'type' => 'counter',
                'help' => 'Sum of policy engine decision processing times in seconds.',
                'value' => $this->getMetric('asap_policy_decision_duration_seconds_sum'),
            ],
        ];

        $output = "";
        foreach ($metrics as $name => $meta) {
            $output .= "# HELP {$name} {$meta['help']}\n";
            $output .= "# TYPE {$name} {$meta['type']}\n";
            $output .= "{$name} {$meta['value']}\n\n";
        }

        return $output;
    }
}
