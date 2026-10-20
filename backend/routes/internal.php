<?php

use App\Http\Controllers\Internal\ArtifactController;
use App\Http\Controllers\Internal\ExecutionController;
use App\Http\Controllers\Internal\InputAssetController;
use App\Http\Middleware\AuthenticateWorker;
use Illuminate\Support\Facades\Route;

Route::prefix('internal/v1')->middleware(AuthenticateWorker::class)->group(function () {
    Route::get('/executions/{id}/assets/{asset}/content', [InputAssetController::class, 'show'])->where(['id' => '[1-9][0-9]{0,17}', 'asset' => '[1-9][0-9]{0,17}']);
    Route::post('/executions/{id}/artifacts', [ArtifactController::class, 'store'])->where('id', '[1-9][0-9]{0,17}');
    Route::post('/executions/{id}/claim', [ExecutionController::class, 'claim'])->where('id', '[1-9][0-9]{0,17}');
    Route::post('/executions/{id}/events', [ExecutionController::class, 'event'])->where('id', '[1-9][0-9]{0,17}');
    Route::post('/executions/{id}/heartbeat', [ExecutionController::class, 'heartbeat'])->where('id', '[1-9][0-9]{0,17}');
});
