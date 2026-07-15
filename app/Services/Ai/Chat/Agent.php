<?php

namespace App\Services\Ai\Chat;

use App\Models\User;
use App\Services\Ai\Chat\Contracts\ChatProvider;
use App\Services\Ai\Tools\ToolRegistry;
use Illuminate\Support\Carbon;

/**
 * Orquestador del asistente (agent loop) con GATE DE CONFIRMACIÓN.
 *
 * Ciclo: preguntar → el modelo pide herramientas → ejecutarlas como el usuario →
 * resultados → repetir → respuesta final. Las herramientas de solo lectura y las
 * escrituras operativas se ejecutan directo; las herramientas marcadas
 * `confirm()` (acciones sensibles) NO se ejecutan: el agente se DETIENE y
 * devuelve `needs_confirmation` para que el usuario apruebe/rechace, y luego
 * `resume()` continúa con su decisión.
 */
class Agent
{
    private const MAX_ITERATIONS = 6;

    /** Reportes visuales generados durante la conversación (para el front). */
    private array $reports = [];

    public function __construct(
        private ChatProvider $provider,
        private ToolRegistry $registry,
        private User $user,
    ) {}

    /** Inicia una conversación. Devuelve un resultado `complete` o `needs_confirmation`. */
    public function run(string $message, array $history = []): array
    {
        $messages = array_merge($history, [['role' => 'user', 'content' => $message]]);
        return $this->loop($messages, [], 0, 0);
    }

    /**
     * Reanuda tras la decisión del usuario. `$messages` es el estado devuelto por
     * `needs_confirmation` (su último turno es del asistente con tool_calls
     * pendientes). `$decisions` mapea tool_call_id → 'allow'|'deny'.
     */
    public function resume(array $messages, array $decisions): array
    {
        $last = end($messages);
        if (! $last || ($last['role'] ?? '') !== 'assistant' || empty($last['tool_calls'])) {
            return $this->result('complete', reply: 'No hay una acción pendiente por confirmar.');
        }

        [$toolMsgs, $actions] = $this->executeToolCalls($last['tool_calls'], $decisions);
        $messages = array_merge($messages, $toolMsgs);

        return $this->loop($messages, $actions, 0, 0);
    }

    /** Núcleo del ciclo. */
    private function loop(array $messages, array $actions, int $usageIn, int $usageOut): array
    {
        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $res = $this->provider->chat($messages, [], $this->systemPrompt());
            $usageIn  += $res['usage']['input']  ?? 0;
            $usageOut += $res['usage']['output'] ?? 0;

            if (empty($res['tool_calls'])) {
                return $this->result('complete', reply: (string) ($res['content'] ?? ''),
                    actions: $actions, usageIn: $usageIn, usageOut: $usageOut, messages: $messages);
            }

            // Registrar el turno del asistente con sus tool_calls.
            $messages[] = ['role' => 'assistant', 'content' => $res['content'], 'tool_calls' => $res['tool_calls']];

            // ¿Alguna herramienta del turno requiere confirmación? → pausar.
            $pending = array_values(array_filter($res['tool_calls'], fn ($tc) => $this->needsConfirm($tc['name'])));
            if ($pending !== []) {
                return $this->result('needs_confirmation',
                    reply: (string) ($res['content'] ?? ''),
                    actions: $actions, usageIn: $usageIn, usageOut: $usageOut, messages: $messages,
                    pending: array_map(fn ($tc) => [
                        'id'          => $tc['id'],
                        'name'        => $tc['name'],
                        'arguments'   => $tc['arguments'],
                        'description' => $this->registry->get($tc['name'])?->description(),
                    ], $pending),
                );
            }

            // Ejecutar todas (ninguna requiere confirmación).
            [$toolMsgs, $trace] = $this->executeToolCalls($res['tool_calls'], []);
            $messages = array_merge($messages, $toolMsgs);
            $actions  = array_merge($actions, $trace);
        }

        return $this->result('complete',
            reply: 'No pude completar la consulta en el número de pasos permitido. Intenta reformular la pregunta.',
            actions: $actions, usageIn: $usageIn, usageOut: $usageOut, messages: $messages);
    }

    /**
     * Ejecuta un conjunto de tool_calls. Para herramientas con confirmación,
     * respeta la decisión (deny por defecto). Devuelve [mensajes-tool, traza].
     */
    private function executeToolCalls(array $toolCalls, array $decisions): array
    {
        $toolMsgs = [];
        $trace    = [];

        foreach ($toolCalls as $tc) {
            $denied = false;

            if ($this->needsConfirm($tc['name'])) {
                $decision = $decisions[$tc['id']] ?? 'deny';
                if ($decision !== 'allow') {
                    $result = ['error' => 'El usuario no autorizó esta acción.'];
                    $denied = true;
                }
            }

            if (! $denied) {
                $result = $this->registry->dispatch($tc['name'], $tc['arguments'], $this->user);
            }

            // Recolectar reportes visuales generados (para mostrarlos en el front).
            if (isset($result['__report__'])) {
                $this->reports[] = $result['__report__'];
            }

            $trace[] = [
                'name'      => $tc['name'],
                'arguments' => $tc['arguments'],
                'ok'        => ! isset($result['error']) && ($result['success'] ?? true) !== false,
                'error'     => $result['error'] ?? null,
                'denied'    => $denied,
            ];

            $toolMsgs[] = [
                'role'         => 'tool',
                'tool_call_id' => $tc['id'],
                'name'         => $tc['name'],
                'content'      => json_encode($result, JSON_UNESCAPED_UNICODE),
            ];
        }

        return [$toolMsgs, $trace];
    }

    private function needsConfirm(string $name): bool
    {
        return (bool) $this->registry->get($name)?->confirm();
    }

    private function result(string $status, string $reply = '', array $actions = [], int $usageIn = 0, int $usageOut = 0, array $messages = [], array $pending = []): array
    {
        return [
            'status'   => $status,
            'reply'    => $reply,
            'actions'  => $actions,
            'pending'  => $pending,
            'reports'  => $this->reports,
            'usage'    => ['input' => $usageIn, 'output' => $usageOut],
            'messages' => $messages,
        ];
    }

    private function systemPrompt(): string
    {
        $name  = $this->user->name;
        $roles = $this->user->getRoleNames()->implode(', ');
        $today = Carbon::now()->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY');

        return <<<PROMPT
        Eres el asistente de "Mantenimientos Siccob", una plataforma de gestión de
        mantenimientos de dispositivos (detección de incendio, CCTV, etc.) para hoteles.

        Estás ayudando a {$name} (rol: {$roles}). Hoy es {$today}.

        Reglas:
        - Responde SIEMPRE en español, de forma clara y concisa.
        - Para cualquier dato del sistema (mantenimientos, clientes, sitios, eventos)
          USA las herramientas disponibles. No inventes datos ni cifras.
        - Para preguntas de CÓMO funciona el sistema o CÓMO hacer un procedimiento
          (p. ej. "¿cómo registro una actividad?"), usa `buscar_en_manual` y
          responde con base en la documentación encontrada.
        - Cuando el usuario pida un REPORTE, resumen visual o análisis presentable,
          PRIMERO reúne los datos con las herramientas de lectura y luego usa
          `crear_reporte` para generarlo con KPIs, tablas y gráficas (barras/líneas/
          pastel). No inventes cifras. Tras generarlo, dile al usuario que puede
          verlo y descargarlo desde el botón que aparece.
        - Las herramientas ya respetan los permisos y el alcance del usuario. Si una
          herramienta devuelve un error o "sin permiso", explícaselo al usuario con
          naturalidad; no reintentes lo mismo.
        - Cuando el usuario pida una acción, INVOCA la herramienta correspondiente
          directamente. NO pidas confirmación tú mismo en texto: el sistema
          intercepta automáticamente las acciones sensibles y le pide confirmación
          al usuario. Tu trabajo es llamar la herramienta con los datos correctos.
        - Si te falta información obligatoria para completar una acción, PREGÚNTASELA
          al usuario de forma clara y conversacional ANTES de invocar la herramienta;
          nunca inventes valores ni uses datos de ejemplo. Si puedes resolver un dato
          con una herramienta de lectura (p. ej. el id de un cliente por su nombre),
          hazlo tú en vez de preguntarlo.
        - Si una herramienta devuelve un error de validación (campo faltante o
          inválido), NO te limites a mostrar el error: explícale al usuario en lenguaje
          natural qué dato falta o está mal, pídeselo, y reintenta con lo que responda.
        - REGLA PARA CREAR/REGISTRAR: si el usuario NO mencionó los campos OPCIONALES
          de esa acción, tu PRIMERA respuesta NO debe ejecutar la herramienta. En su
          lugar: confirma en una frase qué vas a crear, menciona brevemente los campos
          opcionales disponibles, y pregunta si desea agregar alguno o crear así. Ejecuta
          la creación hasta el SIGUIENTE turno, con lo que responda. Única excepción:
          el usuario pide hacerlo de inmediato ("créalo ya", "solo con eso", "sin más").
          Nunca inventes valores para los opcionales.
        - Cuando muestres listas, resume lo relevante (no vuelques JSON crudo).
        PROMPT;
    }
}
