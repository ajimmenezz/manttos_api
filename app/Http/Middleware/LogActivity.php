<?php

namespace App\Http\Middleware;

use App\Support\ActivityLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Registra en la Auditoría cada acción HTTP mutadora (POST/PUT/PATCH/DELETE) que NO
 * haya generado ya filas de cambio de modelo en este request (dedup por contador del
 * ActivityLogger). Así se capturan acciones sin modelo (exports, operaciones a medida)
 * sin duplicar los cambios de datos que ya registra el observer. No registra lecturas.
 */
class LogActivity
{
    /** Primer segmento de ruta ignorado (alto ruido o auditado por su propio flujo). */
    private const IGNORE = [
        'login', 'logout', 'me', 'change-password', 'forgot-password', 'reset-password',
        'notifications', 'chat', 'conversations', 'messages', 'captacion', 'ai-chat', 'ai',
        'telegram-webhook', 'whatsapp-webhook', 'broadcasting', 'media', 'impersonate',
        'device-tokens', 'up',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
                && auth()->check()
                && $response->getStatusCode() < 500
                && ! $this->ignored($request)
            ) {
                $logger = app(ActivityLogger::class);
                // Si la acción ya produjo cambios de modelo, esos ya la describen.
                if ($logger->modelWrites() === 0) {
                    $logger->logRequest($request, $response->getStatusCode());
                }
            }
        } catch (\Throwable $e) {
            report($e); // auditar nunca debe tumbar la petición
        }

        return $response;
    }

    private function ignored(Request $request): bool
    {
        $seg   = explode('/', $request->path());          // p.ej. ['api','events','5']
        $first = ($seg[0] ?? '') === 'api' ? ($seg[1] ?? '') : ($seg[0] ?? '');

        return in_array($first, self::IGNORE, true);
    }
}
