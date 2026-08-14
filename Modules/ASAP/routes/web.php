<?php

use Illuminate\Support\Facades\Route;
use Modules\ASAP\Http\Controllers\ASAPController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('asaps', ASAPController::class)->names('asap');
});
