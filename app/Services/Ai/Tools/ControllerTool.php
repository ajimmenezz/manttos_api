<?php

namespace App\Services\Ai\Tools;

use App\Models\User;
use App\Services\Ai\Support\ControllerInvoker;
use App\Services\Ai\Tools\Contracts\Tool;

/**
 * Herramienta GENÉRICA declarativa: mapea los argumentos del modelo a una
 * llamada a un método de controlador existente (vía ControllerInvoker, como el
 * usuario). Permite exponer "toda la funcionalidad" sin una clase por endpoint.
 *
 * Config:
 *  - name/description/mutating
 *  - verb: GET | POST | PUT
 *  - controller/method
 *  - properties/required: esquema JSON de parámetros
 *  - bindings: [ ['param'=>'maintenance', 'arg'=>'maintenance_id', 'model'=>Maintenance::class, 'label'=>'mantenimiento'] ]
 *       model=null → parámetro de ruta escalar (p. ej. {type}); si no, se hace Model::find($arg).
 *  - shape: 'compact' (listas) | 'full' (objeto)
 *  - success: mensaje al completar (solo mutating)
 */
class ControllerTool implements Tool
{
    public function __construct(
        private string $name,
        private string $description,
        private string $controller,
        private string $method,
        private array $properties = [],
        private array $required = [],
        private array $bindings = [],
        private string $verb = 'GET',
        private bool $mutating = false,
        private string $shape = 'compact',
        private string $success = 'Acción completada.',
        private bool $confirm = false,
    ) {}

    public function name(): string { return $this->name; }
    public function description(): string { return $this->description; }
    public function mutating(): bool { return $this->mutating; }
    public function confirm(): bool { return $this->confirm; }

    public function parameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => (object) $this->properties,
            'required'   => $this->required,
        ];
    }

    public function handle(array $args, User $user): array
    {
        // 1) Resolver bindings de ruta (modelos y escalares).
        $routeParams = [];
        $consumed    = [];
        foreach ($this->bindings as $b) {
            $val = $args[$b['arg']] ?? null;
            $consumed[] = $b['arg'];

            if ($val === null) {
                return $this->fail("Falta {$b['arg']}.");
            }

            if (($b['model'] ?? null) === null) {
                $routeParams[$b['param']] = $val;      // parámetro escalar (p. ej. {type})
                continue;
            }

            $model = ($b['model'])::find($val);
            if (! $model) {
                return $this->fail("No encontré {$b['label']} con id {$val}.");
            }
            $routeParams[$b['param']] = $model;
        }

        // 2) El resto de args van como query (GET) o body (POST/PUT).
        $payload = collect($args)
            ->except($consumed)
            ->filter(fn ($v) => $v !== null && $v !== '')
            ->all();

        // 3) Invocar el controlador como el usuario.
        $result = $this->verb === 'GET'
            ? ControllerInvoker::get($this->controller, $this->method, $user, $payload, $routeParams)
            : ControllerInvoker::post($this->controller, $this->method, $user, $payload, $routeParams, $this->verb);

        // 4) Dar forma a la respuesta.
        if ($this->mutating) {
            if (! ($result['ok'] ?? false)) {
                return ['success' => false, 'error' => $result['error'] ?? 'No se pudo completar la acción.'];
            }
            return ['success' => true, 'message' => $this->success, 'data' => $result['data'] ?? null];
        }

        if (! ($result['ok'] ?? false)) {
            return ['error' => $result['error'] ?? 'No se pudo obtener la información.'];
        }

        return $this->shape === 'compact' ? $this->compact($result['data']) : ['data' => $result['data']];
    }

    private function fail(string $msg): array
    {
        return $this->mutating ? ['success' => false, 'error' => $msg] : ['error' => $msg];
    }

    /** Compacta respuestas paginadas / listas largas para no gastar contexto. */
    private function compact(mixed $data, int $limit = 25): array
    {
        if (is_array($data) && array_key_exists('data', $data) && is_array($data['data'])) {
            $items = $data['data'];
            return [
                'total'          => $data['total'] ?? count($items),
                'count_returned' => min(count($items), $limit),
                'items'          => array_slice($items, 0, $limit),
                'truncated'      => count($items) > $limit || (($data['total'] ?? 0) > count($items)),
            ];
        }
        if (is_array($data) && array_is_list($data)) {
            return [
                'count_returned' => min(count($data), $limit),
                'items'          => array_slice($data, 0, $limit),
                'truncated'      => count($data) > $limit,
            ];
        }
        return ['data' => $data];
    }
}
