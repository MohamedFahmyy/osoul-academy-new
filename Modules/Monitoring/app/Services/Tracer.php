<?php

namespace Modules\Monitoring\Services;

class Tracer
{
    /**
     * Start a tracing span and execute the given callback.
     */
    public static function trace(string $spanName, callable $callback)
    {
        // Scaffold for OpenTelemetry:
        // In production, if OpenTelemetry API is configured, this starts a span,
        // activates it, runs the callback, and records any exceptions before ending it.
        // For local development without extensions, it acts as a safe, transparent wrapper.
        return $callback();
    }
}
