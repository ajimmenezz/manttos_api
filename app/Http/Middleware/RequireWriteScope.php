<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige que el token actual pueda escribir. Las llaves de API de solo lectura
 * (ability ['read']) son rechazadas en endpoints mutadores de /v1. Los tokens de
 * sesión web/móvil tienen ability '*' y pasan sin problema.
 */
class RequireWriteScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->tokenCan('write')) {
            return response()->json([
                'message' => 'Esta llave de API es de solo lectura.',
            ], 403);
        }

        return $next($request);
    }
}
