<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Catalog;
use App\Models\Channel;
use App\Models\CaptureAgentRule;
use App\Services\Capture\AgentRuleAnalyzer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Reglas de comportamiento del agente de captación (SOLO superadmin). Permiten ir
 * corrigiendo/afinando las respuestas del agente sin tocar código: cada regla se
 * inyecta en el system prompt de CaptureAgent (bandeja real + simulador) según su
 * alcance (global / línea / sistema). Se pueden crear desde una página de gestión o
 * "enseñando" desde una respuesta concreta (source_conversation_id + source_context).
 */
class CaptureAgentRuleController extends Controller
{
    private function authorizeSuper(Request $request): void
    {
        abort_unless($request->user()?->hasRole('superadmin'), 403, 'Solo un superadministrador puede gestionar el comportamiento del agente.');
    }

    /** Lista de reglas (con etiquetas de línea/sistema/autor), filtrable por alcance. */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeSuper($request);

        $rules = CaptureAgentRule::query()
            ->with(['channel:id,name', 'system:id,label', 'author:id,name'])
            ->when($request->filled('scope'), fn ($q) => $q->where('scope', $request->scope))
            ->when($request->filled('channel_id'), fn ($q) => $q->where('channel_id', $request->channel_id))
            ->when($request->filled('catalog_id'), fn ($q) => $q->where('catalog_id', $request->catalog_id))
            ->orderBy('scope')->orderBy('sort_order')->orderByDesc('id')
            ->get()
            ->map(fn ($r) => $this->serialize($r));

        return response()->json($rules);
    }

    /** Catálogos para los selectores (líneas + sistemas). */
    public function options(Request $request): JsonResponse
    {
        $this->authorizeSuper($request);

        return response()->json([
            'channels' => Channel::orderBy('name')->get(['id', 'name'])
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name]),
            'systems'  => Catalog::ofType(Catalog::TYPE_SYSTEM)->orderBy('label')->get(['id', 'label'])
                ->map(fn ($c) => ['id' => $c->id, 'label' => $c->label]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeSuper($request);

        $data = $this->validateData($request);
        $data['created_by'] = $request->user()->id;
        if (! isset($data['sort_order'])) {
            $data['sort_order'] = (int) CaptureAgentRule::max('sort_order') + 1;
        }

        $rule = CaptureAgentRule::create($data);

        return response()->json($this->serialize($rule->load(['channel:id,name', 'system:id,label', 'author:id,name'])), 201);
    }

    public function update(Request $request, CaptureAgentRule $rule): JsonResponse
    {
        $this->authorizeSuper($request);

        $rule->update($this->validateData($request, $rule));

        return response()->json($this->serialize($rule->fresh(['channel:id,name', 'system:id,label', 'author:id,name'])));
    }

    public function destroy(Request $request, CaptureAgentRule $rule): JsonResponse
    {
        $this->authorizeSuper($request);
        $rule->delete();

        return response()->json(['ok' => true]);
    }

    /** Evalúa un borrador de regla con la IA (puntaje 0-100 + recomendaciones). */
    public function analyze(Request $request, AgentRuleAnalyzer $analyzer): JsonResponse
    {
        $this->authorizeSuper($request);

        $data = $request->validate([
            'scope'        => ['nullable', 'string'],
            'title'        => ['nullable', 'string', 'max:160'],
            'instruction'  => ['required', 'string', 'max:2000'],
            'example_good' => ['nullable', 'string', 'max:2000'],
            'example_bad'  => ['nullable', 'string', 'max:2000'],
        ]);

        $labels = [
            CaptureAgentRule::SCOPE_GLOBAL  => 'Global (todas las líneas)',
            CaptureAgentRule::SCOPE_CHANNEL => 'Una línea específica',
            CaptureAgentRule::SCOPE_SYSTEM  => 'Un sistema específico',
        ];
        $data['scope_label'] = $labels[$data['scope'] ?? ''] ?? 'Global (todas las líneas)';

        try {
            return response()->json($analyzer->analyze($data));
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // ── Internos ─────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function validateData(Request $request, ?CaptureAgentRule $existing = null): array
    {
        $data = $request->validate([
            'scope'                  => ['required', Rule::in([CaptureAgentRule::SCOPE_GLOBAL, CaptureAgentRule::SCOPE_CHANNEL, CaptureAgentRule::SCOPE_SYSTEM])],
            'channel_id'             => ['nullable', 'required_if:scope,channel', 'exists:channels,id'],
            'catalog_id'             => ['nullable', 'required_if:scope,system', 'exists:catalogs,id'],
            'title'                  => ['nullable', 'string', 'max:160'],
            'instruction'            => ['required', 'string', 'max:2000'],
            'example_bad'            => ['nullable', 'string', 'max:2000'],
            'example_good'           => ['nullable', 'string', 'max:2000'],
            'is_active'              => ['boolean'],
            'sort_order'             => ['nullable', 'integer'],
            'ai_score'               => ['nullable', 'integer', 'min:0', 'max:100'],
            'ai_review'              => ['nullable', 'array'],
            'source_conversation_id' => ['nullable', 'exists:capture_conversations,id'],
            'source_context'         => ['nullable', 'array'],
        ], [], [
            'channel_id' => 'línea',
            'catalog_id' => 'sistema',
        ]);

        // Limpia el alcance no aplicable para no dejar FKs colgando al cambiar de scope.
        if ($data['scope'] !== CaptureAgentRule::SCOPE_CHANNEL) $data['channel_id'] = null;
        if ($data['scope'] !== CaptureAgentRule::SCOPE_SYSTEM)  $data['catalog_id'] = null;

        return $data;
    }

    private function serialize(CaptureAgentRule $r): array
    {
        return [
            'id'            => $r->id,
            'scope'         => $r->scope,
            'channel_id'    => $r->channel_id,
            'channel_name'  => optional($r->channel)->name,
            'catalog_id'    => $r->catalog_id,
            'system_label'  => optional($r->system)->label,
            'title'         => $r->title,
            'instruction'   => $r->instruction,
            'example_bad'   => $r->example_bad,
            'example_good'  => $r->example_good,
            'is_active'     => (bool) $r->is_active,
            'sort_order'    => $r->sort_order,
            'ai_score'      => $r->ai_score,
            'ai_review'     => $r->ai_review,
            'author'        => optional($r->author)->name,
            'source_conversation_id' => $r->source_conversation_id,
            'source_context' => $r->source_context,
            'created_at'    => $r->created_at,
        ];
    }
}
