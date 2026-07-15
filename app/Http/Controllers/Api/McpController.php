<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\Tools\ToolRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Servidor MCP (Model Context Protocol) sobre HTTP/JSON-RPC 2.0. Expone el MISMO
 * ToolRegistry que usa el chat interno, de modo que clientes MCP externos
 * (Claude Desktop, otros agentes) pueden usar las herramientas del sistema.
 *
 * La autenticación es Sanctum: las herramientas se ejecutan como el usuario
 * dueño del token → mismos permisos y alcance que en el resto de la plataforma.
 *
 * Métodos soportados: initialize, notifications/initialized, ping, tools/list,
 * tools/call. Es el subconjunto de MCP relevante para exponer herramientas.
 */
class McpController extends Controller
{
    private const PROTOCOL_VERSION = '2025-06-18';

    public function handle(Request $request): Response|JsonResponse
    {
        $payload = $request->json()->all();

        // Soporte de batch (arreglo de mensajes JSON-RPC).
        if (array_is_list($payload) && $payload !== []) {
            $responses = array_values(array_filter(array_map(fn ($msg) => $this->dispatchRpc($msg, $request), $payload)));
            return response()->json($responses);
        }

        $result = $this->dispatchRpc($payload, $request);

        // Notificaciones (sin id) → 202 sin cuerpo.
        return $result === null
            ? response()->noContent(202)
            : response()->json($result);
    }

    /** Procesa un único mensaje JSON-RPC. Devuelve null para notificaciones. */
    private function dispatchRpc(array $msg, Request $request): ?array
    {
        $id     = $msg['id'] ?? null;
        $method = $msg['method'] ?? '';
        $params = $msg['params'] ?? [];

        // Notificaciones (no llevan id y no esperan respuesta).
        if ($id === null) {
            return null;
        }

        try {
            $result = match ($method) {
                'initialize' => $this->initialize(),
                'ping'       => (object) [],
                'tools/list' => ['tools' => ToolRegistry::make()->toMcpSchema()],
                'tools/call' => $this->callTool($params, $request),
                default      => throw new McpError(-32601, "Método no encontrado: {$method}"),
            };

            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
        } catch (McpError $e) {
            return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $e->getCode(), 'message' => $e->getMessage()]];
        } catch (\Throwable $e) {
            return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32603, 'message' => 'Error interno: ' . $e->getMessage()]];
        }
    }

    private function initialize(): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities'    => ['tools' => ['listChanged' => false]],
            'serverInfo'      => ['name' => 'mantenimientos-mcp', 'version' => '1.0.0'],
        ];
    }

    private function callTool(array $params, Request $request): array
    {
        $name = $params['name'] ?? null;
        $args = $params['arguments'] ?? [];

        if (! $name) {
            throw new McpError(-32602, 'Falta el nombre de la herramienta.');
        }

        $registry = ToolRegistry::make();
        if (! $registry->get($name)) {
            throw new McpError(-32602, "Herramienta desconocida: {$name}");
        }

        // Se ejecuta como el usuario dueño del token Sanctum.
        $result  = $registry->dispatch($name, is_array($args) ? $args : [], $request->user());
        $isError = isset($result['error']) || (($result['success'] ?? true) === false);

        return [
            'content' => [['type' => 'text', 'text' => json_encode($result, JSON_UNESCAPED_UNICODE)]],
            'isError' => $isError,
        ];
    }
}

/** Error JSON-RPC con código. */
class McpError extends \Exception
{
    public function __construct(int $code, string $message)
    {
        parent::__construct($message, $code);
    }
}
