<?php

use App\Http\Controllers\Internal\DeviceHeartbeatController;
use App\Http\Controllers\Internal\RunCallbackController;
use App\Http\Middleware\AuthenticateWorkerRun;
use Illuminate\Support\Facades\Route;

Route::post('api/devices/heartbeat', DeviceHeartbeatController::class)
    ->middleware('throttle:240,1')->name('devices.heartbeat');

Route::prefix('internal')->group(function () {
    Route::get('health', [RunCallbackController::class, 'health'])->middleware('worker.service')->name('internal.health');

    Route::middleware(AuthenticateWorkerRun::class)->group(function () {
        Route::post('runs/{run}/batches', [RunCallbackController::class, 'batch'])->name('internal.runs.batches');
        Route::post('runs/{run}/heartbeat', [RunCallbackController::class, 'heartbeat'])->name('internal.runs.heartbeat');
        Route::post('runs/{run}/complete', [RunCallbackController::class, 'complete'])->name('internal.runs.complete');
    });
});
