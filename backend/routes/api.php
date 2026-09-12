<?php

use App\Http\Controllers\ReadinessController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ProjectController;

Route::get('/ready', ReadinessController::class);

Route::group(['prefix' => '/v1'], function () {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::get('/me', [AuthController::class, 'me'])->middleware('auth:sanctum');
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::get('/organizations/{organization}/projects', [ProjectController::class, 'index'])->middleware('auth:sanctum');
    Route::post('/organizations/{organization}/projects', [ProjectController::class, 'store'])->middleware('auth:sanctum');
    Route::get('/organizations/{organization}/projects/{project}', [ProjectController::class, 'show'])->middleware('auth:sanctum');
    Route::patch('/organizations/{organization}/projects/{project}', [ProjectController::class, 'update'])->middleware('auth:sanctum');
    Route::post('/organizations/{organization}/projects/{project}/archive', [ProjectController::class, 'archive'])->middleware('auth:sanctum');
});
