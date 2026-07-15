<?php

namespace App\Services\Ai\Support;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Ejecuta un método de controlador existente como si lo llamara el usuario
 * autenticado, con parámetros de query dados. Es el corazón de la reutilización:
 * las herramientas de IA NO reimplementan el scoping ni los permisos — llaman al
 * mismo controlador que sirve al front, y toda la autorización (Spatie `->can()`
 * + helpers de alcance) aplica igual.
 *
 * Si el controlador aborta (403/404/422), se captura y se devuelve un error
 * legible para que el modelo lo relate al usuario, en vez de romper el turno.
 */
class ControllerInvoker
{
    /**
     * @param  class-string  $controller
     * @param  array<string,mixed>  $query   parámetros como si fueran ?a=b
     * @param  array<string,mixed>  $routeParams  bindings de ruta adicionales (p. ej. modelos)
     * @return array{ok:bool,data?:mixed,error?:string,status?:int}
     */
    public static function get(string $controller, string $method, User $user, array $query = [], array $routeParams = []): array
    {
        return self::call('GET', $controller, $method, $user, $query, $routeParams);
    }

    /**
     * Igual que get() pero para acciones de escritura (POST/PUT). El `$body` viaja
     * como los campos del request (para `$request->validate(...)`), y `$routeParams`
     * lleva los modelos ya resueltos (p. ej. ['event' => $event]).
     *
     * @return array{ok:bool,data?:mixed,error?:string,status?:int}
     */
    public static function post(string $controller, string $method, User $user, array $body = [], array $routeParams = [], string $verb = 'POST'): array
    {
        return self::call($verb, $controller, $method, $user, $body, $routeParams);
    }

    /** @return array{ok:bool,data?:mixed,error?:string,status?:int} */
    private static function call(string $verb, string $controller, string $method, User $user, array $params, array $routeParams): array
    {
        // Request sintético SOLO para transportar los parámetros al controlador.
        // El usuario se inyecta vía resolver → $request->user() === $user.
        $request = Request::create('/', $verb, $params);
        $request->setUserResolver(fn () => $user);

        // Algunos controladores usan auth()->user() o el helper global request()
        // (sin recibir $request). En el chat real el guard y el request ya son los
        // del usuario; aquí lo garantizamos para MCP/colas/pruebas: fijamos el
        // guard y enlazamos el request sintético como el request() global.
        $previous        = \Illuminate\Support\Facades\Auth::user();
        $previousRequest = app()->bound('request') ? app('request') : null;
        \Illuminate\Support\Facades\Auth::setUser($user);
        app()->instance('request', $request);

        try {
            $instance = app($controller);
            $response = app()->call([$instance, $method], array_merge(['request' => $request], $routeParams));

            $data = $response instanceof JsonResponse ? $response->getData(true) : $response;

            return ['ok' => true, 'data' => $data];
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Errores de validación → mensaje legible para que el modelo los relate.
            return ['ok' => false, 'error' => collect($e->errors())->flatten()->implode(' '), 'status' => 422];
        } catch (HttpException $e) {
            return [
                'ok'     => false,
                'error'  => $e->getMessage() ?: 'No autorizado para esta acción.',
                'status' => $e->getStatusCode(),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Error al ejecutar la herramienta: ' . $e->getMessage()];
        } finally {
            if ($previous) {
                \Illuminate\Support\Facades\Auth::setUser($previous);
            }
            if ($previousRequest) {
                app()->instance('request', $previousRequest);
            }
        }
    }
}
