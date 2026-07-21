<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ScopesEvents;
use App\Http\Controllers\Api\Concerns\MaintenanceActivityFilters;
use App\Models\AppSetting;
use App\Models\Catalog;
use App\Models\Directory;
use App\Models\Event;
use App\Models\EventStatus;
use App\Models\EventStatusHistory;
use App\Models\EventType;
use App\Models\EventTypeField;
use App\Models\EventTypeTransition;
use App\Models\Site;
use App\Models\User;
use App\Services\Notifications\Notifier;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Support\EventAudience;
use App\Support\EventFolio;
use App\Support\EventSla;
use App\Support\NotificationType;

class EventController extends Controller
{
    use ScopesEvents;
    use MaintenanceActivityFilters;

    /**
     * Resuelve la fecha de ocurrencia respetando el flag global. Si la captura manual
     * está apagada (o no viene fecha), usa $fallback (now() al crear, o null al editar
     * para conservar la existente). Si está prendida y la fecha es válida no futura, la respeta.
     */
    private function resolveOccurredAt(?string $provided, $fallback)
    {
        if (! AppSetting::executionDateAllowed() || empty($provided)) {
            return $fallback;
        }
        $d = Carbon::parse($provided);
        return $d->startOfDay()->lte(Carbon::today()) ? $d : $fallback;
    }

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
            // Pool: sólo eventos sin asignar (bandeja "Sin asignar") o por asignado.
            ->when($request->boolean('unassigned'),   fn ($q) => $q->whereNull('events.assigned_to'))
            ->when($request->filled('assigned_to'),   fn ($q) => $q->where('events.assigned_to', $request->assigned_to))
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
            // Fotos del levantamiento (URLs de MediaController), para evidencia + diagnóstico IA.
            'images'        => 'nullable|array|max:20',
            'images.*'      => 'string|max:1000',
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
                'images'        => ! empty($data['images']) ? array_values($data['images']) : null,
                'created_by'    => $user->id,
                'occurred_at'   => $this->resolveOccurredAt($data['occurred_at'] ?? null, now()),
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

        // Aviso (bandeja + push) a quienes les toca el evento, menos el creador.
        app(Notifier::class)->send(
            EventAudience::interested($event),
            NotificationType::EVENT_CREATED,
            [
                'event_id'   => $event->id,
                'folio'      => $event->folio,
                'site'       => $site->name,
                'actor_id'   => $user->id,
                'actor_name' => $user->name,
            ],
            "Nuevo evento {$event->folio}",
            Str::limit(trim($event->description), 120),
            $user->id,
        );

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
        // Solicitante (portal): puede levantar en los sitios de su(s) cliente(s)/sitio(s) asociados.
        if ($user->hasRole('solicitante')) {
            return $user->solicitanteClients()->where('clients.id', $site->client_id)->exists()
                || $user->solicitanteSites()->where('sites.id', $site->id)->exists();
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
            'client:id,name,short_name', 'device:id,name,device_type,location,custom_fields', 'creator:id,name', 'assignee:id,name',
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

    /**
     * Diagnóstico INICIAL DE APOYO: la IA analiza las fotos del evento + la descripción,
     * cruza con la base de conocimiento del sistema y devuelve una orientación (no un
     * veredicto). Idempotente-reejecutable: recalcula y sobreescribe el guardado.
     */
    public function diagnose(Request $request, Event $event, \App\Services\Ai\Vision\EventDiagnosisService $svc): JsonResponse
    {
        $this->authorizeAccess($request, $event);

        if (empty($event->images)) {
            return response()->json(['message' => 'Este evento no tiene fotos para analizar.'], 422);
        }
        if (! $svc->canDiagnose($event)) {
            return response()->json(['message' => 'La IA de visión no está configurada. Configura un modelo con visión (OpenAI GPT-4o o Claude) en Ajustes → Asistente IA.'], 422);
        }

        try {
            $diagnosis = $svc->diagnose($event);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'No se pudo generar el diagnóstico: ' . $e->getMessage()], 422);
        }

        return response()->json([
            'message'         => 'Diagnóstico de apoyo generado.',
            'ai_diagnosis'    => $diagnosis,
            'ai_diagnosis_at' => $event->ai_diagnosis_at,
        ]);
    }

    /**
     * (Re)genera el "Resumen de IA" del evento (síntesis de formulario, notas,
     * estados y comentarios). Se usa al abrir el detalle cuando está desactualizado
     * o con el botón Actualizar. El agente de captación también lo aprovecha.
     */
    public function aiSummary(Request $request, Event $event, \App\Services\Ai\EventSummaryService $svc): JsonResponse
    {
        $this->authorizeAccess($request, $event);

        if (! $svc->isOperational()) {
            return response()->json(['message' => 'La IA no está configurada. Actívala en Ajustes → Asistente IA.'], 422);
        }

        try {
            $summary = $svc->summarize($event);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'No se pudo generar el resumen: ' . $e->getMessage()], 422);
        }

        return response()->json([
            'ai_summary'       => $summary,
            'ai_summary_at'    => $event->ai_summary_at,
            'ai_summary_stale' => false,
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
            'images'       => 'nullable|array|max:20',
            'images.*'     => 'string|max:1000',
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
                'occurred_at'  => $this->resolveOccurredAt($data['occurred_at'] ?? null, null),
            ], fn ($v) => $v !== null) + [
                'impact'        => $impact,
                'urgency'       => $urgency,
                'priority'      => $newPriority,
                'priority_auto' => $newAuto,
                'scheduled_attention_at' => $scheduledAt,
                'field_values'  => $data['field_values'] ?? $event->field_values,
                'images'        => array_key_exists('images', $data) ? array_values((array) $data['images']) : $event->images,
            // device_id por presencia: permite ligar Y desligar (null) explícitamente.
            ] + (array_key_exists('device_id', $data) ? ['device_id' => $data['device_id']] : []));

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

        // Aviso a interesados + el creador (aunque no esté en el audience), menos quien lo movió.
        $actor = $request->user();
        $statusLabel = $target?->label ?? 'nuevo estado';
        app(Notifier::class)->send(
            array_merge(EventAudience::interested($event), array_filter([$event->created_by])),
            NotificationType::EVENT_STATUS_CHANGED,
            [
                'event_id'   => $event->id,
                'folio'      => $event->folio,
                'status'     => $statusLabel,
                'actor_id'   => $actor->id,
                'actor_name' => $actor->name,
            ],
            "Evento {$event->folio}: {$statusLabel}",
            "{$actor->name} cambió el estado a «{$statusLabel}».",
            $actor->id,
        );

        return response()->json(['message' => 'Estado actualizado.', 'event' => $event->fresh(['status'])]);
    }

    // ─── Asignación: pool → ingeniero (y reasignar / retirar) ─────
    /**
     * Asigna (o reasigna / retira) un evento a un ingeniero. El asignado debe tener
     * alcance al sitio del evento. Deja constancia en el historial y notifica al asignado.
     * `assigned_to` nulo devuelve el evento al pool "sin asignar".
     */
    public function assign(Request $request, Event $event): JsonResponse
    {
        $this->authorizeAccess($request, $event);
        abort_unless($request->user()->can('events.assign'), 403, 'No puedes asignar eventos.');

        $data = $request->validate([
            'assigned_to' => 'nullable|integer|exists:users,id',
        ]);

        $assigneeId = $data['assigned_to'] ?? null;
        $assignee   = null;

        if ($assigneeId !== null) {
            // Sólo ingenieros/técnicos con alcance al sitio (clientes o sitios asignados).
            $assignee = $this->assignableUsers($event)->firstWhere('id', $assigneeId);
            abort_unless($assignee, 422, 'El usuario seleccionado no puede atender este sitio.');
        }

        // Sin cambios reales → responder sin ruido ni notificación.
        if ((int) $event->assigned_to === (int) $assigneeId) {
            return response()->json(['message' => 'Sin cambios.', 'event' => $event->fresh(['assignee:id,name'])]);
        }

        DB::transaction(function () use ($event, $assigneeId, $assignee, $request) {
            $event->update(['assigned_to' => $assigneeId]);

            EventStatusHistory::create([
                'event_id'       => $event->id,
                'from_status_id' => $event->status_id,
                'to_status_id'   => $event->status_id, // la asignación no cambia el estado
                'user_id'        => $request->user()->id,
                'note'           => $assignee ? "Asignado a {$assignee->name}" : 'Asignación retirada (regresa al pool)',
                'created_at'     => now(),
            ]);
        });

        // Aviso (bandeja + push) al ingeniero asignado, salvo que se asigne a sí mismo.
        if ($assignee && $assignee->id !== $request->user()->id) {
            app(Notifier::class)->send(
                [$assignee->id],
                NotificationType::EVENT_ASSIGNED,
                [
                    'event_id'   => $event->id,
                    'folio'      => $event->folio,
                    'actor_id'   => $request->user()->id,
                    'actor_name' => $request->user()->name,
                ],
                "Te asignaron el evento {$event->folio}",
                "{$request->user()->name} te asignó este evento.",
                $request->user()->id,
            );
        }

        return response()->json([
            'message' => $assignee ? "Evento asignado a {$assignee->name}." : 'Asignación retirada; el evento vuelve al pool.',
            'event'   => $event->fresh(['assignee:id,name', 'status:id,key,label,color']),
        ]);
    }

    /** Candidatos a asignación (para el selector del front). */
    public function assignable(Request $request, Event $event): JsonResponse
    {
        $this->authorizeAccess($request, $event);
        abort_unless($request->user()->can('events.assign'), 403, 'No autorizado para esta acción.');
        return response()->json($this->assignableUsers($event));
    }

    /**
     * Usuarios que pueden atender el sitio del evento: ingenieros/técnicos con alcance
     * al cliente (client_engineers) o al sitio (site_engineers). Es el conjunto válido
     * para asignar; coincide con la visibilidad de un ingeniero sobre el evento.
     */
    private function assignableUsers(Event $event)
    {
        $ids = collect()
            ->merge(DB::table('client_engineers')->where('client_id', $event->client_id)->pluck('user_id'))
            ->merge(DB::table('site_engineers')->where('site_id', $event->site_id)->pluck('user_id'))
            ->unique()->values();

        return User::whereIn('id', $ids)->where('is_active', true)
            ->orderBy('name')->get(['id', 'name', 'email']);
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

    // ─── Búsqueda de dispositivos del directorio (sitio × sistema) ────
    /**
     * Dispositivos del directorio (sitio + sistema) para ligar a un evento. Búsqueda
     * server-side por nombre / DID / ubicación con tope, para no cargar miles.
     */
    public function deviceOptions(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('events.create') || $user->can('events.view'), 403);

        $data = $request->validate([
            'site_id'     => 'required|exists:sites,id',
            'system_id'   => 'required|exists:catalogs,id',
            'search'      => 'nullable|string|max:100',
            'dir_filters' => 'nullable', // filtros por campo del directorio (JSON o array)
        ]);

        $site = Site::findOrFail($data['site_id']);
        abort_unless($this->userCanUseSite($request, $site), 403, 'No tienes acceso a este sitio.');

        // Clave real del DID en este sistema (campo field_type='did'); fallback a 'did'.
        $didKey = $this->didKeyFor($data['system_id'], $site->client_id);

        $q = \App\Models\Device::whereHas('directory', fn ($d) =>
                $d->where('site_id', $data['site_id'])->where('catalog_id', $data['system_id'])->where('is_active', true)
            )->where('is_active', true);

        // Filtros por cualquier campo del directorio (select = igualdad, texto = "contiene").
        $dirFilters = $this->parseMaintenanceFilters($request)['dir'];
        if (! empty($dirFilters)) {
            $meta = $this->directoryFilterMeta((int) $data['system_id'], (int) $data['site_id'], $site->client_id);
            $this->applyDirectoryFilters($q, $dirFilters, $meta['modes']);
        }

        $search = trim((string) ($data['search'] ?? ''));
        if ($search !== '') {
            $q->where(fn ($w) => $w
                ->where('name', 'ilike', "%{$search}%")
                ->orWhere('location', 'ilike', "%{$search}%")
                ->orWhereRaw('custom_fields->>? ilike ?', [$didKey, "%{$search}%"]));
        }

        $total   = (clone $q)->count();
        $limit   = 30;
        $devices = $q->orderBy('name')->limit($limit)->get(['id', 'name', 'device_type', 'location', 'custom_fields']);

        return response()->json([
            'devices' => $devices->map(fn ($d) => [
                'id'            => $d->id,
                'name'          => $d->name,
                'device_type'   => $d->device_type,
                'location'      => $d->location,
                'did'           => is_array($d->custom_fields) ? ($d->custom_fields[$didKey] ?? null) : null,
                'custom_fields' => is_array($d->custom_fields) ? $d->custom_fields : (object) [],
            ])->values(),
            'total'     => $total,
            'limit'     => $limit,
            'truncated' => $total > $limit,
        ]);
    }

    /**
     * Definiciones de los campos del directorio de un sistema (para ligar dispositivo
     * a un evento): etiqueta, clave y tipo, ordenados. Fuente única para el bloque de
     * lectura de datos del directorio (detalle/alta/hoja de servicio).
     */
    public function directoryFields(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('events.create') || $user->can('events.view'), 403);

        $data = $request->validate([
            'system_id' => 'required|exists:catalogs,id',
            'client_id' => 'nullable|exists:clients,id',
        ]);

        return response()->json([
            'fields' => $this->directoryFieldDefs($data['system_id'], $data['client_id'] ?? null),
        ]);
    }

    /**
     * Filtros disponibles del directorio (sitio × sistema) para el buscador de
     * dispositivos al ligarlo a un evento: por cada campo filtrable, sus valores
     * distintos (select de baja cardinalidad) o modo "contiene" (texto).
     */
    public function deviceFilterOptions(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('events.create') || $user->can('events.view'), 403);

        $data = $request->validate([
            'site_id'   => 'required|exists:sites,id',
            'system_id' => 'required|exists:catalogs,id',
        ]);

        $site = Site::findOrFail($data['site_id']);
        abort_unless($this->userCanUseSite($request, $site), 403, 'No tienes acceso a este sitio.');

        $meta = $this->directoryFilterMeta((int) $data['system_id'], (int) $data['site_id'], $site->client_id);

        return response()->json(['directory' => $meta['available']]);
    }

    /**
     * Metadatos de los filtros de directorio para un sitio × sistema: los campos
     * (base + override por cliente, ver directoryFieldDefs) con sus valores distintos
     * y su modo (select/text según cardinalidad). Reusa valueStrings/umbral del trait
     * MaintenanceActivityFilters. Devuelve `available` (para la UI) y `modes` (para
     * aplicar los filtros en deviceOptions).
     *
     * @return array{available: array<int,array>, modes: array<string,string>}
     */
    private function directoryFilterMeta(int $systemId, int $siteId, ?int $clientId): array
    {
        $defs = collect($this->directoryFieldDefs($systemId, $clientId))
            ->reject(fn ($f) => in_array($f['field_type'], $this->filterSkipTypes, true));

        if ($defs->isEmpty()) {
            return ['available' => [], 'modes' => []];
        }

        $devices = \App\Models\Device::whereHas('directory', fn ($d) =>
                $d->where('site_id', $siteId)->where('catalog_id', $systemId)->where('is_active', true)
            )->where('is_active', true)->get(['custom_fields']);

        $available = [];
        $modes = [];
        foreach ($defs as $f) {
            $vals = $devices
                ->flatMap(fn ($d) => $this->valueStrings(
                    is_array($d->custom_fields) ? ($d->custom_fields[$f['field_key']] ?? null) : null
                ))
                ->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values();
            if ($vals->isEmpty()) continue;

            $mode = $vals->count() <= $this->filterSelectThreshold ? 'select' : 'text';
            $modes[$f['field_key']] = $mode;
            $available[] = [
                'key'        => $f['field_key'],
                'label'      => $f['label'],
                'field_type' => $f['field_type'],
                'mode'       => $mode,
                'values'     => $mode === 'select' ? $vals->all() : [],
            ];
        }

        return ['available' => $available, 'modes' => $modes];
    }

    /** Campos activos del directorio de un sistema (base + override por cliente), ordenados. */
    private function directoryFieldDefs(int $systemId, ?int $clientId): array
    {
        $rows = \App\Models\SystemField::where('catalog_id', $systemId)
            ->where('is_active', true)
            ->when($clientId !== null,
                fn ($q) => $q->where(fn ($w) => $w->whereNull('client_id')->orWhere('client_id', $clientId)),
                fn ($q) => $q->whereNull('client_id'))
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'client_id', 'field_key', 'label', 'field_type', 'sort_order']);

        // El override por cliente (client_id) gana sobre el base (client_id null) con la misma clave.
        return $rows->sortBy(fn ($f) => $f->client_id === null ? 0 : 1)
            ->keyBy('field_key')
            ->sortBy('sort_order')
            ->map(fn ($f) => [
                'field_key'  => $f->field_key,
                'label'      => $f->label,
                'field_type' => $f->field_type,
            ])->values()->all();
    }

    /** Clave del campo DID (field_type='did') del sistema; fallback 'did'. */
    private function didKeyFor(int $systemId, ?int $clientId): string
    {
        $did = \App\Models\SystemField::where('catalog_id', $systemId)
            ->where('field_type', 'did')
            ->where('is_active', true)
            ->when($clientId !== null,
                fn ($q) => $q->where(fn ($w) => $w->whereNull('client_id')->orWhere('client_id', $clientId)))
            ->orderByRaw('client_id is null')
            ->value('field_key');

        return $did ?: 'did';
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
        } elseif ($user->hasRole('solicitante')) {
            // Portal: sitios de su(s) cliente(s)/sitio(s) asociados (pivotes dedicados).
            $clientIds = $user->solicitanteClients()->pluck('clients.id');
            $siteIds   = $user->solicitanteSites()->pluck('sites.id');
            $siteQuery->where(fn ($q) => $q->whereIn('client_id', $clientIds)->orWhereIn('id', $siteIds));
        } else {
            // Rol nuevo con events.create: alcance por sus asignaciones de cliente/sitio.
            $clientIds = $user->clientsAsAdmin()->pluck('clients.id');
            $siteIds   = $user->sitesAsAdmin()->pluck('sites.id');
            if ($clientIds->isNotEmpty() || $siteIds->isNotEmpty()) {
                $siteQuery->where(fn ($q) => $q
                    ->whereIn('client_id', $clientIds)
                    ->orWhereIn('id', $siteIds));
            } else {
                $siteQuery->whereRaw('1 = 0');
            }
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
