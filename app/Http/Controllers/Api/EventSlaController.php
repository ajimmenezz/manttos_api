<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Event;
use App\Models\EventSlaSetting;
use App\Models\EventSlaTier;
use App\Models\EventStatus;
use App\Support\EventSla;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Administración del catálogo de SLA de eventos (solo event-sla.manage):
 * niveles de atención, matriz Impacto×Urgencia, objetivos por prioridad,
 * calendario de servicio y el mapeo estado→nivel. Config global + override por cliente.
 */
class EventSlaController extends Controller
{
    private function authorize(Request $request): void
    {
        abort_unless($request->user()->can('event-sla.manage'), 403);
    }

    /** Contexto completo para la pantalla de configuración. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize($request);

        $clientsWithOverride = EventSlaSetting::whereNotNull('client_id')
            ->with('client:id,name,short_name')->get()
            ->map(fn ($s) => ['id' => $s->client_id, 'name' => optional($s->client)->name ?? '—'])
            ->values();

        return response()->json([
            'tiers'      => EventSlaTier::orderBy('sort_order')->orderBy('id')->get(),
            'impacts'    => Event::IMPACTS,
            'urgencies'  => Event::URGENCIES,
            'priorities' => Event::PRIORITIES,
            'statuses'   => EventStatus::orderBy('sort_order')->orderBy('id')
                ->get(['id', 'label', 'color', 'sla_tier_id', 'is_active']),
            'clients_with_override' => $clientsWithOverride,
            'defaults'   => EventSla::defaults(),
        ]);
    }

    /** Settings efectivos + fila cruda para un alcance (global si no se manda client_id). */
    public function settings(Request $request): JsonResponse
    {
        $this->authorize($request);
        $clientId = $request->filled('client_id') ? (int) $request->client_id : null;

        $raw = EventSlaSetting::where('client_id', $clientId)->first();

        return response()->json([
            'client_id'   => $clientId,
            'is_override' => $clientId !== null && $raw !== null,
            'effective'   => EventSla::resolve($clientId),
            'raw'         => $raw?->only(['enabled', 'matrix', 'priorities', 'calendar']),
        ]);
    }

    /** Guarda (upsert) los settings de un alcance. client_id null = global. */
    public function saveSettings(Request $request): JsonResponse
    {
        $this->authorize($request);

        $data = $request->validate([
            'client_id'  => 'nullable|integer|exists:clients,id',
            'enabled'    => 'required|boolean',
            'matrix'     => 'required|array',
            'priorities' => 'required|array',
            'calendar'   => 'required|array',
            'calendar.mode'      => 'required|in:business,24_7',
            'calendar.work_days' => 'array',
            'calendar.start'     => 'nullable|string',
            'calendar.end'       => 'nullable|string',
            'calendar.timezone'  => 'nullable|string',
            'calendar.holidays'  => 'array',
        ]);

        $clientId = $data['client_id'] ?? null;

        $setting = EventSlaSetting::updateOrCreate(
            ['client_id' => $clientId],
            [
                'enabled'    => $data['enabled'],
                'matrix'     => $this->sanitizeMatrix($data['matrix']),
                'priorities' => $this->sanitizePriorities($data['priorities']),
                'calendar'   => $data['calendar'],
            ],
        );
        EventSla::flushCache();

        return response()->json(['message' => 'Configuración de SLA guardada.', 'setting' => $setting]);
    }

    /** Elimina el override de un cliente (vuelve a heredar el global). */
    public function deleteSettings(Request $request, Client $client): JsonResponse
    {
        $this->authorize($request);
        EventSlaSetting::where('client_id', $client->id)->delete();
        EventSla::flushCache();
        return response()->json(['message' => 'Override eliminado. El cliente hereda el SLA global.']);
    }

    // ── Niveles de atención ────────────────────────────────────────────────
    public function storeTier(Request $request): JsonResponse
    {
        $this->authorize($request);
        $data = $request->validate(['label' => 'required|string|max:120']);
        $tier = EventSlaTier::create([
            'key'        => $this->uniqueTierKey($data['label']),
            'label'      => $data['label'],
            'sort_order' => (EventSlaTier::max('sort_order') ?? 0) + 1,
            'is_active'  => true,
        ]);
        return response()->json(['message' => 'Nivel creado.', 'tier' => $tier], 201);
    }

    public function updateTier(Request $request, EventSlaTier $tier): JsonResponse
    {
        $this->authorize($request);
        $data = $request->validate([
            'label'     => 'required|string|max:120',
            'is_active' => 'boolean',
        ]);
        $tier->update($data); // el key es estable
        return response()->json(['message' => 'Nivel actualizado.', 'tier' => $tier]);
    }

    public function destroyTier(Request $request, EventSlaTier $tier): JsonResponse
    {
        $this->authorize($request);
        // Al borrar, los estados que lo apuntaban quedan sin nivel (nullOnDelete).
        $tier->delete();
        return response()->json(['message' => 'Nivel eliminado.']);
    }

    public function reorderTiers(Request $request): JsonResponse
    {
        $this->authorize($request);
        $request->validate(['tier_ids' => 'required|array', 'tier_ids.*' => 'integer|exists:event_sla_tiers,id']);
        DB::transaction(function () use ($request) {
            foreach ($request->tier_ids as $i => $id) {
                EventSlaTier::where('id', $id)->update(['sort_order' => $i]);
            }
        });
        return response()->json(['message' => 'Orden guardado.']);
    }

    /** Mapeo masivo estado → nivel de atención. */
    public function saveStatusTiers(Request $request): JsonResponse
    {
        $this->authorize($request);
        $data = $request->validate([
            'map'   => 'present|array',
            'map.*' => 'nullable|integer|exists:event_sla_tiers,id',
        ]);
        DB::transaction(function () use ($data) {
            foreach ($data['map'] as $statusId => $tierId) {
                EventStatus::where('id', (int) $statusId)->update(['sla_tier_id' => $tierId ?: null]);
            }
        });
        return response()->json(['message' => 'Mapeo de estados guardado.']);
    }

    private function uniqueTierKey(string $label): string
    {
        $base = Str::slug($label, '_') ?: 'nivel';
        $key = $base;
        $i = 2;
        while (EventSlaTier::where('key', $key)->exists()) {
            $key = $base . '_' . $i++;
        }
        return $key;
    }

    /** Solo conserva celdas válidas impacto|urgencia → prioridad. */
    private function sanitizeMatrix(array $matrix): array
    {
        $out = [];
        foreach (Event::IMPACTS as $imp) {
            foreach (Event::URGENCIES as $urg) {
                $k = "$imp|$urg";
                $v = $matrix[$k] ?? null;
                if (in_array($v, Event::PRIORITIES, true)) $out[$k] = $v;
            }
        }
        return $out;
    }

    /** Normaliza { priority => { scheduled, targets:{tier_key:hours} } }. */
    private function sanitizePriorities(array $priorities): array
    {
        $tierKeys = EventSlaTier::pluck('key')->all();
        $out = [];
        foreach (Event::PRIORITIES as $p) {
            $conf = $priorities[$p] ?? [];
            $scheduled = (bool) ($conf['scheduled'] ?? false);
            $targets = [];
            foreach (($conf['targets'] ?? []) as $tk => $hrs) {
                if (in_array($tk, $tierKeys, true) && $hrs !== null && $hrs !== '' && is_numeric($hrs)) {
                    $targets[$tk] = (float) $hrs;
                }
            }
            $out[$p] = ['scheduled' => $scheduled, 'targets' => $scheduled ? [] : $targets];
        }
        return $out;
    }
}
