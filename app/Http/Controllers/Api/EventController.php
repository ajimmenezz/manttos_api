<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ScopesEvents;
use App\Models\Catalog;
use App\Models\Directory;
use App\Models\Event;
use App\Models\EventStatus;
use App\Models\EventStatusHistory;
use App\Models\EventType;
use App\Models\EventTypeField;
use App\Models\EventTypeTransition;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\EventFolio;
use App\Support\EventSla;

class EventController extends Controller
{
    use ScopesEvents;

    private function authorizeAccess(Request $request, Event $event): void
    {
        $ok = $this->scopeEvents($request, Event::query()->where('events.id', $event->id))->exists();
        abort_unless($ok, 403, 'No tienes acceso a este evento.');
    }

    // ─── Listado ──────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('events.view'), 403);

        $query = Event::query()
            ->with(['eventType:id,label,nature,color', 'system:id,label', 'status:id,key,label,color,is_terminal',
                    'site:id,name,client_id', 'client:id,name,short_name', 'creator:id,name', 'assignee:id,name'])
            ->when($request->filled('client_id'),     fn ($q) => $q->where('events.client_id', $request->client_id))
            ->when($request->filled('site_id'),       fn ($q) => $q->where('events.site_id', $request->site_id))
            ->when($request->filled('system_id'),     fn ($q) => $q->where('events.system_id', $request->system_id))
            ->when($request->filled('event_type_id'), fn ($q) => $q->where('events.event_type_id', $request->event_type_id))
            ->when($request->filled('status_id'),     fn ($q) => $q->where('events.status_id', $request->status_id))
            ->when($request->filled('priority'),      fn ($q) => $q->where('events.priority', $request->priority))
            ->when($request->filled('search'),        fn ($q) => $q->where(fn ($s) =>
                $s->where('events.folio', 'ilike', "%{$request->search}%")
                  ->orWhere('events.description', 'ilike', "%{$request->search}%")))
            ->orderByDesc('events.created_at');
        // La exclusión de clientes/sitios archivados la aplica scopeEvents() (trait).

        $this->scopeEvents($request, $query);

        return response()->json($query->paginate($request->per_page ?? 100));
    }

    // ─── Crear (dos flujos) ───────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('events.create'), 403);

        $data = $request->validate([
            'site_id'       => 'required|exists:sites,id',
            'system_id'     => 'required|exists:catalogs,id',
            'event_type_id' => 'required|exists:event_types,id',
            'device_id'     => 'nullable|exists:devices,id',
            'priority'      => 'nullable|in:' . implode(',', Event::PRIORITIES),
            'impact'        => 'nullable|in:' . implode(',', Event::IMPACTS),
            'urgency'       => 'nullable|in:' . implode(',', Event::URGENCIES),
            'scheduled_attention_at' => 'nullable|date',
            'description'   => 'required|string|max:5000',
            'occurred_at'   => 'nullable|date',
            // Captura rica opcional (la app móvil del ingeniero documenta el formulario al alta).
            'field_values'  => 'nullable|array',
            // Llave de idempotencia generada por el cliente (evita duplicados si se reintenta el alta).
            'client_uuid'   => 'nullable|string|max:64',
        ]);

        // Idempotencia: si ya existe un evento con este client_uuid, devolverlo en
        // vez de crear otro (la app pudo reenviar tras un corte de red / cierre).
        if (! empty($data['client_uuid'])) {
            $existing = Event::where('client_uuid', $data['client_uuid'])->first();
            if ($existing) {
                return response()->json(['message' => 'Evento ya registrado.', 'event' => $existing->fresh(), 'folio' => $existing->folio], 200);
            }
        }

        $site = Site::findOrFail($data['site_id']);
        abort_unless($this->userCanUseSite($request, $site), 403, 'No tienes acceso a este sitio.');

        $type = EventType::findOrFail($data['event_type_id']);

        // Prioridad = matriz Impacto×Urgencia (auto) salvo override manual explícito.
        [$priority, $priorityAuto] = $this->resolvePriority($data, $type, $site->client_id);
        $settings = EventSla::resolve($site->client_id);
        $scheduledAt = (EventSla::isScheduled($priority, $settings) && ! empty($data['scheduled_attention_at']))
            ? $data['scheduled_attention_at'] : null;

        // Por defecto el alta sólo registra la descripción → 'pendiente_captura'. Si el ingeniero
        // (events.fill-form) ya viene con el formulario documentado, arranca en 'en_progreso'.
        $hasForm = $user->can('events.fill-form') && ! empty($data['field_values']);
        $statusKey = $hasForm ? 'en_progreso' : 'pendiente_captura';
        $status = EventStatus::where('key', $statusKey)->first()
            ?? EventStatus::where('is_initial', true)->orderBy('sort_order')->first();
        abort_if(! $status, 422, 'No hay estados configurados.');

        $event = DB::transaction(function () use ($data, $site, $type, $status, $user, $hasForm, $priority, $priorityAuto, $scheduledAt) {
            $event = Event::create([
                'folio'         => EventFolio::next($site->client),
                'client_uuid'   => $data['client_uuid'] ?? null,
                'client_id'     => $site->client_id,
                'site_id'       => $site->id,
                'system_id'     => $data['system_id'],
                'event_type_id' => $type->id,
                'device_id'     => $data['device_id'] ?? null,
                'status_id'     => $status->id,
                'priority'      => $priority,
                'impact'        => $data['impact'] ?? null,
                'urgency'       => $data['urgency'] ?? null,
                'priority_auto' => $priorityAuto,
                'scheduled_attention_at' => $scheduledAt,
                'description'   => $data['description'],
                'field_values'  => $hasForm ? $data['field_values'] : null,
                'created_by'    => $user->id,
                'occurred_at'   => $data['occurred_at'] ?? now(),
            ]);

            EventStatusHistory::create([
                'event_id'       => $event->id,
                'from_status_id' => null,
                'to_status_id'   => $status->id,
                'user_id'        => $user->id,
                'note'           => 'Evento creado',
                'created_at'     => now(),
            ]);

            return $event;
        });

        return response()->json(['message' => 'Evento creado.', 'event' => $event->fresh(), 'folio' => $event->folio], 201);
    }

    private function userCanUseSite(Request $request, Site $site): bool
    {
        $user = $request->user();
        if ($user->hasAnyRole(['superadmin', 'admin'])) return true;
        if ($user->hasRole('admin-cliente')) return $user->clientsAsAdmin()->where('clients.id', $site->client_id)->exists();
        if ($user->hasRole('admin-sitio')) return $user->sitesAsAdmin()->where('sites.id', $site->id)->exists();
        if ($user->hasRole('ingeniero')) {
            if ($user->sitesAsEngineer()->where('sites.id', $site->id)->exists()) return true;
            return $user->clientsAsEngineer()->where('clients.id', $site->client_id)->exists();
        }
        return false;
    }

    /**
     * Determina la prioridad de un evento nuevo y si fue automática.
     * Prioridad = matriz Impacto×Urgencia (auto); si el usuario mandó una prioridad que
     * no coincide con la derivada, es override manual; sin impacto/urgencia se usa la
     * prioridad enviada o la del tipo.
     *
     * @return array{0:string,1:bool}  [priority, priority_auto]
     */
    private function resolvePriority(array $data, EventType $type, int $clientId): array
    {
        $settings = EventSla::resolve($clientId);
        $derived  = EventSla::priorityFor($data['impact'] ?? null, $data['urgency'] ?? null, $settings);
        $explicit = $data['priority'] ?? null;

        if ($explicit && $derived && $explicit !== $derived) return [$explicit, false];
        if ($derived)  return [$derived, true];
        if ($explicit) return [$explicit, false];
        return [$type->default_priority, false];
    }

    /** Contexto de SLA para el alta de un evento (según el cliente del sitio). */
    public function slaContext(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('events.create'), 403);
        $request->validate(['site_id' => 'required|exists:sites,id']);
        $site = Site::findOrFail($request->site_id);

        $settings = EventSla::resolve($site->client_id);
        // scheduled por prioridad, para que el front sepa cuándo pedir fecha programada.
        $scheduled = [];
        foreach (Event::PRIORITIES as $p) {
            $scheduled[$p] = EventSla::isScheduled($p, $settings);
        }

        return response()->json([
            'impacts'    => Event::IMPACTS,
            'urgencies'  => Event::URGENCIES,
            'matrix'     => $settings['matrix'],
            'scheduled'  => $scheduled,
            'enabled'    => (bool) ($settings['enabled'] ?? true),
        ]);
    }

    // ─── Detalle ──────────────────────────────────────────────────
    public function show(Request $request, Event $event): JsonResponse
    {
        $this->authorizeAccess($request, $event);

        $event->load(['eventType', 'system:id,label', 'status', 'site:id,name,client_id',
            'client:id,name,short_name', 'device:id,device_name,device_type', 'creator:id,name', 'assignee:id,name',
            'history.toStatus:id,label,color,sla_tier_id', 'history.fromStatus:id,label', 'history.user:id,name']);

        // Formulario del tipo×sistema (campos activos) para render/captura.
        $fields = EventTypeField::where('event_type_id', $event->event_type_id)
            ->where('system_id', $event->system_id)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        // Medición de SLA (usa el historial ya cargado + el mapeo estado→nivel).
        $settings   = EventSla::resolve($event->client_id);
        $tiers      = EventSla::tiers();
        $statusMap  = EventStatus::whereNotNull('sla_tier_id')->pluck('sla_tier_id', 'id')->all();
        $sla        = EventSla::measure($event, $settings, $statusMap, $tiers);

        return response()->json([
            'event'                => $event,
            'fields'               => $fields,
            'allowed_transitions'  => $this->allowedNext($event),
            'sla'                  => $sla,
        ]);
    }

    // ─── Capturar / editar formulario (ingeniero) ─────────────────
    public function update(Request $request, Event $event): JsonResponse
    {
        $this->authorizeAccess($request, $event);
        abort_unless($request->user()->can('events.fill-form'), 403, 'No puedes capturar el formulario.');

        $data = $request->validate([
            'description'  => 'sometimes|string|max:5000',
            'priority'     => 'nullable|in:' . implode(',', Event::PRIORITIES),
            'impact'       => 'nullable|in:' . implode(',', Event::IMPACTS),
            'urgency'      => 'nullable|in:' . implode(',', Event::URGENCIES),
            'scheduled_attention_at' => 'nullable|date',
            'device_id'    => 'nullable|exists:devices,id',
            'occurred_at'  => 'nullable|date',
            'field_values' => 'nullable|array',
        ]);

        // Prioridad: si cambian impacto/urgencia y la prioridad era automática (o el usuario
        // no forzó una), se recalcula de la matriz; un valor explícito distinto = override.
        $settings   = EventSla::resolve($event->client_id);
        $impact     = array_key_exists('impact', $data)  ? $data['impact']  : $event->impact;
        $urgency    = array_key_exists('urgency', $data) ? $data['urgency'] : $event->urgency;
        $derived    = EventSla::priorityFor($impact, $urgency, $settings);
        $explicit   = $data['priority'] ?? null;
        if ($explicit && (! $derived || $explicit !== $derived)) {
            $newPriority = $explicit; $newAuto = false;
        } elseif ($derived) {
            $newPriority = $derived; $newAuto = true;
        } else {
            $newPriority = $event->priority; $newAuto = $event->priority_auto;
        }
        $scheduledAt = EventSla::isScheduled($newPriority, $settings)
            ? (array_key_exists('scheduled_attention_at', $data) ? $data['scheduled_attention_at'] : $event->scheduled_attention_at)
            : null;

        DB::transaction(function () use ($event, $data, $request, $impact, $urgency, $newPriority, $newAuto, $scheduledAt) {
            $event->update(array_filter([
                'description'  => $data['description'] ?? null,
                'device_id'    => $data['device_id'] ?? null,
                'occurred_at'  => $data['occurred_at'] ?? null,
            ], fn ($v) => $v !== null) + [
                'impact'        => $impact,
                'urgency'       => $urgency,
                'priority'      => $newPriority,
                'priority_auto' => $newAuto,
                'scheduled_attention_at' => $scheduledAt,
                'field_values'  => $data['field_values'] ?? $event->field_values,
            ]);

            // Si estaba pendiente de captura y ya se llenó, avanza a 'en_progreso'.
            if (optional($event->status)->key === 'pendiente_captura' && ! empty($data['field_values'])) {
                $next = EventStatus::where('key', 'en_progreso')->first();
                if ($next) {
                    $from = $event->status_id;
                    $event->update(['status_id' => $next->id]);
                    EventStatusHistory::create([
                        'event_id' => $event->id, 'from_status_id' => $from, 'to_status_id' => $next->id,
                        'user_id' => $request->user()->id, 'note' => 'Formulario capturado', 'created_at' => now(),
                    ]);
                }
            }
        });

        return response()->json(['message' => 'Evento actualizado.', 'event' => $event->fresh()]);
    }

    // ─── Cambiar estado (respeta transiciones tipo→general) ───────
    public function changeStatus(Request $request, Event $event): JsonResponse
    {
        $this->authorizeAccess($request, $event);
        abort_unless($request->user()->can('events.change-status'), 403, 'No puedes cambiar el estado.');

        $data = $request->validate([
            'to_status_id' => 'required|integer|exists:event_statuses,id',
            'note'         => 'nullable|string|max:1000',
        ]);

        $allowedIds = collect($this->allowedNext($event))->pluck('id')->all();
        abort_unless(in_array((int) $data['to_status_id'], $allowedIds, true), 422,
            'Transición no permitida desde el estado actual.');

        // Estados que exigen el formulario: no se puede avanzar sin capturar los campos
        // obligatorios del evento (p. ej. no marcar "Resuelto" sin documentar).
        $target = EventStatus::find($data['to_status_id']);
        if ($target && $target->requires_form) {
            $missing = $this->missingRequiredFields($event);
            abort_if(! empty($missing), 422,
                "Para pasar a «{$target->label}» primero debes capturar el formulario: " . implode(', ', $missing) . '.');
        }

        // Estados que exigen nota: el cambio no procede sin una nota escrita.
        if ($target && $target->requires_note) {
            abort_if(trim((string) ($data['note'] ?? '')) === '', 422,
                "Para pasar a «{$target->label}» debes escribir una nota.");
        }

        DB::transaction(function () use ($event, $data, $request) {
            $from = $event->status_id;
            $event->update(['status_id' => $data['to_status_id']]);
            EventStatusHistory::create([
                'event_id' => $event->id, 'from_status_id' => $from, 'to_status_id' => $data['to_status_id'],
                'user_id' => $request->user()->id, 'note' => $data['note'] ?? null, 'created_at' => now(),
            ]);
        });

        return response()->json(['message' => 'Estado actualizado.', 'event' => $event->fresh(['status'])]);
    }

    /** Estados siguientes permitidos: override por tipo si existe, si no el general. */
    private function allowedNext(Event $event): array
    {
        $typeRows = EventTypeTransition::where('event_type_id', $event->event_type_id)->get();
        if ($typeRows->isNotEmpty()) {
            $nextIds = $typeRows->where('from_status_id', $event->status_id)->pluck('to_status_id');
        } else {
            $nextIds = DB::table('event_status_transitions')
                ->where('from_status_id', $event->status_id)->pluck('to_status_id');
        }
        return EventStatus::whereIn('id', $nextIds)->where('is_active', true)
            ->orderBy('sort_order')->get()->all();
    }

    /**
     * Campos obligatorios del formulario (tipo×sistema) que aún no se han capturado.
     * Se omiten los campos con condiciones de visibilidad (no se evalúan en el servidor)
     * y las leyendas; devuelve las etiquetas faltantes.
     */
    private function missingRequiredFields(Event $event): array
    {
        $fields = EventTypeField::where('event_type_id', $event->event_type_id)
            ->where('system_id', $event->system_id)
            ->where('is_active', true)
            ->where('is_required', true)
            ->orderBy('sort_order')->get();

        $values = $event->field_values ?? [];
        $missing = [];
        foreach ($fields as $f) {
            if ($f->field_type === 'leyenda') continue;
            if (! empty($f->visibility)) continue; // condicional: no se evalúa aquí
            $v = $values[$f->field_key] ?? null;
            $empty = $v === null || $v === '' || (is_array($v) && count($v) === 0);
            if ($empty) $missing[] = $f->label;
        }
        return $missing;
    }

    // ─── Formulario por (tipo, sistema) para el create ────────────
    public function formFields(Request $request): JsonResponse
    {
        $request->validate([
            'event_type_id' => 'required|exists:event_types,id',
            'system_id'     => 'required|exists:catalogs,id',
        ]);
        $fields = EventTypeField::where('event_type_id', $request->event_type_id)
            ->where('system_id', $request->system_id)
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('id')
            ->get();
        return response()->json($fields);
    }

    // ─── Paquete de arranque para la app móvil (offline) ──────────
    /**
     * Todo lo necesario para que un ingeniero genere/documente eventos sin conexión:
     * sus sitios + sistemas por sitio + tipos + campos + opciones de catálogo + estados.
     */
    public function syncBundle(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('events.create'), 403);

        // Sitios que el usuario puede usar para eventos, según su rol.
        $siteQuery = Site::query()->where('is_active', true);
        if ($user->hasAnyRole(['superadmin', 'admin'])) {
            // todos
        } elseif ($user->hasRole('admin-cliente')) {
            $siteQuery->whereIn('client_id', $user->clientsAsAdmin()->pluck('clients.id'));
        } elseif ($user->hasRole('admin-sitio')) {
            $siteQuery->whereIn('id', $user->sitesAsAdmin()->pluck('sites.id'));
        } elseif ($user->hasRole('ingeniero')) {
            $siteQuery->whereIn('id', $this->engineerSiteIds($user));
        } else {
            $siteQuery->whereRaw('1 = 0');
        }
        $sites = $siteQuery->with('client:id,name,short_name')->orderBy('name')->get(['id', 'name', 'client_id']);

        // Sistemas disponibles por sitio (directorios activos).
        $siteIds = $sites->pluck('id');
        $dirRows = Directory::whereIn('site_id', $siteIds)->where('is_active', true)
            ->get(['site_id', 'catalog_id']);
        $systemsBySite = $dirRows->groupBy('site_id')
            ->map(fn ($g) => $g->pluck('catalog_id')->unique()->values());

        $sitesOut = $sites->map(fn ($s) => [
            'id'          => $s->id,
            'name'        => $s->name,
            'client_id'   => $s->client_id,
            'client_name' => optional($s->client)->short_name ?: optional($s->client)->name,
            'system_ids'  => $systemsBySite->get($s->id, collect())->all(),
        ])->values();

        // Sistemas (catalogs type=system) referenciados por esos sitios.
        $systems = Catalog::whereIn('id', $dirRows->pluck('catalog_id')->unique())
            ->where('is_active', true)->orderBy('label')->get(['id', 'label']);

        // Tipos de evento activos + sistemas vinculados.
        $types = EventType::where('is_active', true)->with('linkedSystems:id')
            ->orderBy('sort_order')->orderBy('label')->get();
        $typesOut = $types->map(fn ($t) => [
            'id'               => $t->id,
            'label'            => $t->label,
            'nature'           => $t->nature,
            'color'            => $t->color,
            'default_priority' => $t->default_priority,
            'system_ids'       => $t->linkedSystems->pluck('id')->values(),
        ])->values();

        // Campos por (tipo × sistema) activos.
        $fields = EventTypeField::whereIn('event_type_id', $types->pluck('id'))
            ->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();

        // Opciones de catálogo para list/multiselect (para que funcionen offline).
        $catalogOptions = [];
        foreach ($fields->pluck('catalog_type')->filter()->unique() as $ct) {
            $catalogOptions[$ct] = Catalog::where('type', $ct)->where('is_active', true)
                ->orderBy('label')->get(['id', 'label']);
        }

        $statuses = EventStatus::where('is_active', true)->orderBy('sort_order')
            ->get(['id', 'key', 'label', 'color', 'is_initial', 'is_terminal', 'requires_form', 'requires_note']);

        return response()->json([
            'sites'             => $sitesOut,
            'systems'           => $systems,
            'event_types'       => $typesOut,
            'event_type_fields' => $fields,
            'catalog_options'   => $catalogOptions,
            'statuses'          => $statuses,
            'generated_at'      => now()->toISOString(),
        ]);
    }
}
