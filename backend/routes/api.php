<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AssetController;
use App\Http\Controllers\Api\V1\ExecutionController;
use App\Http\Controllers\Api\V1\PipelineController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\ReadinessController;
use Illuminate\Support\Facades\Route;

Route::get('/ready', ReadinessController::class);

Route::group(['prefix' => '/v1'], function () {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::get('/me', [AuthController::class, 'me'])->middleware('auth:sanctum');
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::get('/organizations/{organization}/assets', [AssetController::class, 'index'])->middleware('auth:sanctum');
    Route::post('/organizations/{organization}/assets', [AssetController::class, 'store'])->middleware('auth:sanctum');
    Route::get('/organizations/{organization}/assets/{asset}/download', [AssetController::class, 'download'])->middleware('auth:sanctum');
    Route::get('/organizations/{organization}/projects', [ProjectController::class, 'index'])->middleware('auth:sanctum');
    Route::post('/organizations/{organization}/projects', [ProjectController::class, 'store'])->middleware('auth:sanctum');
    Route::get('/organizations/{organization}/projects/{project}', [ProjectController::class, 'show'])->middleware('auth:sanctum');
    Route::patch('/organizations/{organization}/projects/{project}', [ProjectController::class, 'update'])->middleware('auth:sanctum');
    Route::post('/organizations/{organization}/projects/{project}/archive', [ProjectController::class, 'archive'])->middleware('auth:sanctum');
    Route::get('/node-types', [PipelineController::class, 'types'])->middleware('auth:sanctum');
    Route::get('/organizations/{organization}/projects/{project}/pipelines', [PipelineController::class, 'index'])->middleware('auth:sanctum');
    Route::post('/organizations/{organization}/projects/{project}/pipelines', [PipelineController::class, 'store'])->middleware('auth:sanctum');
    Route::get('/organizations/{organization}/pipelines/{pipeline}', [PipelineController::class, 'show'])->middleware('auth:sanctum');
    Route::patch('/organizations/{organization}/pipelines/{pipeline}', [PipelineController::class, 'update'])->middleware('auth:sanctum');
    Route::put('/organizations/{organization}/pipelines/{pipeline}/graph', [PipelineController::class, 'saveGraph'])->middleware('auth:sanctum');
    Route::post('/organizations/{organization}/pipelines/{pipeline}/validate', [PipelineController::class, 'validateGraph'])->middleware('auth:sanctum');
    Route::post('/organizations/{organization}/pipelines/{pipeline}/archive', [PipelineController::class, 'archive'])->middleware('auth:sanctum');
    Route::post('/organizations/{organization}/pipelines/{pipeline}/executions', [ExecutionController::class, 'store'])->middleware('auth:sanctum');
    Route::get('/organizations/{organization}/executions/{execution}', [ExecutionController::class, 'show'])->middleware('auth:sanctum');
});
