<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        // Canales privados del chat. La ruta de autorización NO se registra aquí
        // (usaría el guard web): va en routes/api.php bajo auth:sanctum.
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Token-based auth only — no CSRF needed for API routes

        $middleware->alias([
            'role'       => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {

        // 401 — no autenticado
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $_, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'No autenticado.'], 401);
            }
        });

        // 403 — sin permiso (Spatie)
        $exceptions->render(function (\Spatie\Permission\Exceptions\UnauthorizedException $_, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403);
            }
        });

        // 422 — errores de validación: el message muestra el primer error real, no el genérico
        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                $firstError = collect($e->errors())->flatten()->first()
                    ?? 'Los datos proporcionados no son válidos.';
                return response()->json([
                    'message' => $firstError,
                    'errors'  => $e->errors(),
                ], 422);
            }
        });

        // 404 — ruta/recurso inexistente. Para peticiones a la API (o que esperan JSON)
        // devolvemos un mensaje ad-hoc del proyecto; el navegador ve la vista branded
        // resources/views/errors/404.blade.php.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $_, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'El recurso solicitado no existe en la API del Sistema de Mantenimientos.',
                    'status'  => 404,
                ], 404);
            }
        });

        // 405 — método HTTP no permitido para la ruta.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException $_, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'El método HTTP no está permitido para esta ruta de la API del Sistema de Mantenimientos.',
                    'status'  => 405,
                ], 405);
            }
        });

    })->create();
