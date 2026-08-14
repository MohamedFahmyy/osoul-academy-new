<?php

use Illuminate\Support\Facades\Route;
use Modules\ASAP\Http\Controllers\Api\SessionController;
use Modules\ASAP\Http\Controllers\Api\TelemetryController;
use Modules\ASAP\Http\Controllers\Api\SecurityEventController;

Route::post('v1/asap/session/handshake', [SessionController::class, 'handshake'])
    ->middleware('throttle:asap_session')
    ->name('asap.session.handshake');

Route::post('v1/asap/telemetry/heartbeat', [TelemetryController::class, 'heartbeat'])
    ->middleware('throttle:asap_heartbeat')
    ->name('asap.telemetry.heartbeat');

Route::post('v1/asap/event', [SecurityEventController::class, 'report'])
    ->middleware('throttle:asap_event')
    ->name('asap.event.report');

Route::middleware(['auth'])->prefix('v1/asap')->group(function () {
    Route::get('/session/{id}', [SessionController::class, 'show'])
        ->middleware('throttle:asap_session')
        ->name('asap.session.show');
});

Route::middleware(['auth:sanctum'])->prefix('v1/asap')->group(function () {
    Route::post('/session', [SessionController::class, 'start'])
        ->middleware('throttle:asap_session')
        ->name('asap.session.start');
});
