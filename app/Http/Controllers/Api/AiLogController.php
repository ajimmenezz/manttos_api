<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiInteraction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Registro/observabilidad del asistente de IA (solo super-admin, `config.manage`).
 * Lista las interacciones con su costo, tokens, duración y calificaciones, más
 * un resumen agregado.
 */
class AiLogController extends Controller
{
    /** GET /ai/interactions — lista paginada con filtros. */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('config.manage'), 403);

        $query = AiInteraction::query()
            ->with('user:id,name')
            ->withCount([
                'feedback as good_count' => fn ($q) => $q->where('rating', 'good'),
                'feedback as bad_count'  => fn ($q) => $q->where('rating', 'bad'),
            ])
            ->when($request->filled('user_id'),  fn ($q) => $q->where('user_id', $request->user_id))
            ->when($request->filled('source'),   fn ($q) => $q->where('source', $request->source))
            ->when($request->filled('status'),   fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('rating'),   fn ($q) => $q->whereHas('feedback', fn ($f) => $f->where('rating', $request->rating)))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'),   fn ($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->when($request->filled('search'),   fn ($q) => $q->where('prompt', 'ilike', "%{$request->search}%"))
            ->orderByDesc('created_at');

        return response()->json($query->paginate($request->per_page ?? 25));
    }

    /** GET /ai/interactions/stats — resumen agregado (histórico y del mes). */
    public function stats(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('config.manage'), 403);

        $all   = $this->aggregate(AiInteraction::query());
        $month = $this->aggregate(AiInteraction::whereMonth('created_at', now()->month)->whereYear('created_at', now()->year));

        $feedback = \App\Models\AiFeedback::selectRaw("count(*) filter (where rating='good') as good, count(*) filter (where rating='bad') as bad")->first();

        return response()->json([
            'all_time' => $all,
            'this_month' => $month,
            'by_source' => $this->bySource(AiInteraction::query()),
            'by_source_month' => $this->bySource(AiInteraction::whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)),
            'feedback' => [
                'good' => (int) ($feedback->good ?? 0),
                'bad'  => (int) ($feedback->bad ?? 0),
            ],
        ]);
    }

    /** Desglose de costo/consumo por origen (assistant / captacion / ingest). */
    private function bySource($query): array
    {
        return $query->selectRaw('
                coalesce(source, \'assistant\') as source,
                count(*) as interactions,
                coalesce(sum(cost_usd),0) as cost_usd,
                coalesce(sum(input_tokens),0) as input_tokens,
                coalesce(sum(output_tokens),0) as output_tokens
            ')
            ->groupBy('source')
            ->orderByDesc('cost_usd')
            ->get()
            ->map(fn ($r) => [
                'source'        => $r->source,
                'interactions'  => (int) $r->interactions,
                'cost_usd'      => round((float) $r->cost_usd, 4),
                'input_tokens'  => (int) $r->input_tokens,
                'output_tokens' => (int) $r->output_tokens,
            ])->all();
    }

    /** GET /ai/interactions/{interaction} — detalle completo. */
    public function show(Request $request, AiInteraction $interaction): JsonResponse
    {
        abort_unless($request->user()->can('config.manage'), 403);

        $interaction->load(['user:id,name', 'feedback.user:id,name']);

        return response()->json($interaction);
    }

    private function aggregate($query): array
    {
        $row = $query->selectRaw('
            count(*) as interactions,
            coalesce(sum(cost_usd),0) as cost_usd,
            coalesce(sum(input_tokens),0) as input_tokens,
            coalesce(sum(output_tokens),0) as output_tokens,
            coalesce(avg(duration_ms),0) as avg_duration_ms,
            count(*) filter (where status = \'error\') as errors
        ')->first();

        return [
            'interactions'    => (int) $row->interactions,
            'cost_usd'        => round((float) $row->cost_usd, 4),
            'input_tokens'    => (int) $row->input_tokens,
            'output_tokens'   => (int) $row->output_tokens,
            'avg_duration_ms' => (int) round((float) $row->avg_duration_ms),
            'errors'          => (int) $row->errors,
        ];
    }
}
