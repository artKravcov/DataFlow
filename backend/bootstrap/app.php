<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use App\Http\Middleware\AddRequestId;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/api/health',
    )

    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->append(AddRequestId::class);
    })

    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'VALIDATION_FAILED',
                        'message' => 'The given data was invalid',
                        'fields' => $e->errors(),
                        'request_id' => $request->attributes->get('request_id'),
                    ]
                ], 422);
            };
        });
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'UNAUTHENTICATED',
                        'message' => 'Authentication required',
                        'fields' => (object) [],
                        'request_id' => $request->attributes->get('request_id'),
                    ]
                ], 401);
            };
        });
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
             if ($request->is('api/*') || $request->expectsJson()) {
                $status = $e->getStatusCode();
                if ($status == 404) {
                    return response()->json([
                        'error' => [
                            'code' => 'RESOURCE_NOT_FOUND',
                            'message' => 'The requested resource was not found',
                            'fields' => (object) [],
                            'request_id' => $request->attributes->get('request_id'),
                        ]
                    ], $status);
                } elseif ($status === 403) {
                    return response()->json([
                        'error' => [
                            'code' => 'FORBIDDEN',
                            'message' => 'You do not have permission to perform this action',
                            'fields' => (object) [],
                            'request_id' => $request->attributes->get('request_id'),
                        ]
                    ], $status);
                } elseif ($status === 419) {
                    return response()->json([
                        'error' => [
                            'code' => 'CSRF_TOKEN_MISMATCH',
                            'message' => 'CSRF token mismatch',
                            'fields' => (object) [],
                            'request_id' => $request->attributes->get('request_id'),
                        ]
                    ], $status);
                }
            };
        });

    })->create();
