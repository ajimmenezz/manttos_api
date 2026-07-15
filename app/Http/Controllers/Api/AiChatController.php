<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiFeedback;
use App\Models\AiInteraction;
use App\Models\AiReport;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Chat\Agent;
use App\Services\Ai\Chat\ChatProviderFactory;
use App\Services\Ai\Tools\ToolRegistry;
use App\Support\AiPricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Endpoint de chat del asistente. Disponible para cualquier usuario autenticado:
 * las herramientas ya aplican los permisos y el alcance de cada quien. Soporta
 * el gate de confirmación: si el agente devuelve `needs_confirmation`, el front
 * muestra las acciones pendientes y confirma vía /ai/chat/confirm.
 */
class AiChatController extends Controller
{
    /** GET /ai/status */
    public function status(Request $request): JsonResponse
    {
        $resolved = AiSettings::resolved();
        $ready    = $resolved['enabled']
            && ! empty($resolved['model'])
            && ($resolved['local'] || AiSettings::hasApiKey());

        return response()->json([
            'enabled'  => $resolved['enabled'],
            'ready'    => $ready,
            'provider' => $resolved['provider'],
            'model'    => $resolved['model'],
        ]);
    }

    /** POST /ai/chat — envía un mensaje. */
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message'           => 'required|string|max:4000',
            'conversation_id'   => 'sometimes|nullable|uuid',
            'history'           => 'sometimes|array|max:40',
            'history.*.role'    => 'required_with:history|string|in:user,assistant',
            'history.*.content' => 'required_with:history|string',
        ]);

        [$agent, $resolved, $err] = $this->makeAgent($request);
        if ($err) {
            return $err;
        }

        $history = collect($data['history'] ?? [])
            ->map(fn ($m) => ['role' => $m['role'], 'content' => $m['content']])
            ->all();

        $conversationId = $data['conversation_id'] ?? (string) Str::uuid();
        $startedAt      = microtime(true);

        try {
            $result = $agent->run($data['message'], $history);
        } catch (\Throwable $e) {
            $this->record($request, $conversationId, $resolved, $data['message'], null, ['input' => 0, 'output' => 0], [], (int) round((microtime(true) - $startedAt) * 1000), 'error', $e->getMessage());
            return response()->json(['message' => 'No se pudo obtener respuesta del asistente.', 'error' => $e->getMessage()], 502);
        }

        return $this->respond($request, $result, $conversationId, $resolved, $data['message'], $startedAt);
    }

    /** POST /ai/chat/confirm — reanuda tras aprobar/rechazar acciones. */
    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'conversation_id' => 'required|uuid',
            'prompt'          => 'required|string|max:4000',
            'messages'        => 'required|array',
            'decisions'       => 'required|array',   // { tool_call_id: "allow"|"deny" }
        ]);

        [$agent, $resolved, $err] = $this->makeAgent($request);
        if ($err) {
            return $err;
        }

        $startedAt = microtime(true);

        try {
            $result = $agent->resume($data['messages'], $data['decisions']);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'No se pudo ejecutar la acción.', 'error' => $e->getMessage()], 502);
        }

        return $this->respond($request, $result, $data['conversation_id'], $resolved, $data['prompt'], $startedAt);
    }

    /** GET /ai/reports/{report} — sirve el HTML del reporte (solo su dueño). */
    public function report(Request $request, AiReport $report)
    {
        abort_unless($report->user_id === $request->user()->id || $request->user()->can('config.manage'), 403);

        return response($report->html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /** POST /ai/feedback — califica una interacción. */
    public function feedback(Request $request): JsonResponse
    {
        $data = $request->validate([
            'interaction_id' => 'required|integer|exists:ai_interactions,id',
            'rating'         => 'required|string|in:good,bad',
            'comment'        => 'sometimes|nullable|string|max:2000',
        ]);

        $feedback = AiFeedback::updateOrCreate(
            ['ai_interaction_id' => $data['interaction_id'], 'user_id' => $request->user()->id],
            ['rating' => $data['rating'], 'comment' => $data['comment'] ?? null],
        );

        return response()->json(['message' => '¡Gracias por tu retroalimentación!', 'rating' => $feedback->rating]);
    }

    // ── Internos ──────────────────────────────────────────────────────────────

    /** Construye el agente o devuelve un error de configuración. */
    private function makeAgent(Request $request): array
    {
        $resolved = AiSettings::resolved();

        if (! $resolved['enabled']) {
            return [null, $resolved, response()->json(['message' => 'El asistente de IA está desactivado.'], 422)];
        }
        if (empty($resolved['model'])) {
            return [null, $resolved, response()->json(['message' => 'Falta configurar el modelo del asistente.'], 422)];
        }
        if (! $resolved['local'] && ! AiSettings::hasApiKey()) {
            return [null, $resolved, response()->json(['message' => 'Falta configurar la API key del asistente.'], 422)];
        }

        $registry = ToolRegistry::make();
        $provider = ChatProviderFactory::make($resolved, $registry);
        $agent    = new Agent($provider, $registry, $request->user());

        return [$agent, $resolved, null];
    }

    /** Da forma a la respuesta según sea `complete` o `needs_confirmation`. */
    private function respond(Request $request, array $result, string $conversationId, array $resolved, string $prompt, float $startedAt): JsonResponse
    {
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        // Acción(es) pendientes de confirmación → no se registra aún.
        if (($result['status'] ?? 'complete') === 'needs_confirmation') {
            return response()->json([
                'status'          => 'needs_confirmation',
                'conversation_id' => $conversationId,
                'reply'           => $result['reply'],
                'pending'         => $result['pending'],
                'reports'         => $result['reports'] ?? [],
                'messages'        => $result['messages'],   // estado para reanudar
            ]);
        }

        $interaction = $this->record($request, $conversationId, $resolved, $prompt, $result['reply'], $result['usage'], $result['actions'], $durationMs, 'ok', null);

        return response()->json([
            'status'          => 'complete',
            'id'              => $interaction->id,
            'conversation_id' => $conversationId,
            'reply'           => $result['reply'],
            'actions'         => $result['actions'],
            'reports'         => $result['reports'] ?? [],
            'usage'           => $result['usage'],
            'cost_usd'        => (float) $interaction->cost_usd,
            'duration_ms'     => $durationMs,
        ]);
    }

    private function record(Request $request, string $conversationId, array $resolved, string $prompt, ?string $reply, array $usage, array $actions, int $durationMs, string $status, ?string $error): AiInteraction
    {
        $inTok  = (int) ($usage['input']  ?? 0);
        $outTok = (int) ($usage['output'] ?? 0);
        $cost   = AiPricing::costFromTokens($inTok, $outTok, (float) $resolved['price_in'], (float) $resolved['price_out']);

        return AiInteraction::create([
            'conversation_id' => $conversationId,
            'user_id'         => $request->user()->id,
            'prompt'          => $prompt,
            'reply'           => $reply,
            'provider'        => $resolved['provider'],
            'model'           => $resolved['model'],
            'input_tokens'    => $inTok,
            'output_tokens'   => $outTok,
            'cost_usd'        => $cost,
            'price_in'        => $resolved['price_in'],
            'price_out'       => $resolved['price_out'],
            'duration_ms'     => $durationMs,
            'iterations'      => max(1, count($actions)),
            'actions'         => $actions,
            'status'          => $status,
            'error'           => $error,
        ]);
    }
}
