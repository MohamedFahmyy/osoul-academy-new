<?php

use Illuminate\Support\Facades\Route;
use Modules\Monitoring\Http\Controllers\Api\MetricsController;

Route::prefix('v1')->group(function () {
    Route::get('/metrics', [MetricsController::class, 'index'])->name('monitoring.metrics');
});
