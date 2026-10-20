<?php

use App\Http\Controllers\Internal\ExecutionController;
use App\Http\Middleware\AuthenticateWorker;
use Illuminate\Support\Facades\Route;

Route::prefix('internal/v1')->middleware(AuthenticateWorker::class)->group(function () {
    Route::post('/executions/{id}/claim', [ExecutionController::class, 'claim'])->where('id', '[1-9][0-9]{0,17}');
    Route::post('/executions/{id}/events', [ExecutionController::class, 'event'])->where('id', '[1-9][0-9]{0,17}');
    Route::post('/executions/{id}/heartbeat', [ExecutionController::class, 'heartbeat'])->where('id', '[1-9][0-9]{0,17}');
});
