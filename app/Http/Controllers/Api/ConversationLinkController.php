<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationLink;
use App\Models\Event;
use App\Models\Maintenance;
use App\Models\Site;
use App\Models\User;
use App\Services\Ai\Support\ControllerInvoker;
use App\Services\Chat\ChatService;
use App\Support\ChatScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Fase 3 del chat: integración BIDIRECCIONAL con la operación.
 *
 *   (a) desde un evento se abre/crea su conversación  → GET /events/{event}/conversation
 *   (b) desde una conversación se crea un EVENTO      → POST /conversations/{id}/events
 *   (c) y se puede ligar a algo que ya existe         → POST /conversations/{id}/link
 *
 * El evento NO se crea a mano: se pasa por `EventController::store` con
 * `ControllerInvoker` (mismo patrón que la captación por WhatsApp), para heredar folio
 * por cliente, matriz de prioridad, validaciones, alcance e idempotencia. Duplicar esa
 * lógica aquí sería la forma más rápida de que el chat genere eventos inconsistentes.
 */
class ConversationLinkController extends Controller
{
    public function __construct(private ChatService $chat)
    {
    }

    /** Ligas actuales de la conversación, ya resueltas a algo mostrable. */
    public function index(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeParticipant($request, $conversation);

        return response()->json(['data' => $this->serializeLinks($conversation)]);
    }

    /**
     * Crea un EVENTO desde la conversación y lo deja ligado a ella.
     * Además publica un mensaje en el hilo con el folio, para que quede la traza
     * de "de esta plática salió este evento".
     */
    public function storeEvent(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $this->authorizeParticipant($request, $conversation);
        abort_unless($user->can('events.create'), 403, 'No tienes permiso para crear eventos.');

        $data = $request->validate([
            'site_id'       => ['required', 'integer', 'exists:sites,id'],
            'system_id'     => ['required', 'integer', 'exists:catalogs,id'],
            'event_type_id' => ['required', 'integer', 'exists:event_types,id'],
            'description'   => ['required', 'string', 'max:5000'],
            'priority'      => ['nullable', 'string'],
            'device_id'     => ['nullable', 'integer', 'exists:devices,id'],
            'client_uuid'   => ['nullable', 'string', 'max:64'],
        ]);

        $res = ControllerInvoker::post(EventController::class, 'store', $user, array_filter(
            $data, fn ($v) => $v !== null
        ));

        if (! ($res['ok'] ?? false)) {
            return response()->json(
                ['message' => $res['error'] ?? 'No se pudo crear el evento.'],
                $res['status'] ?? 422
            );
        }

        $payload  = $res['data'] ?? [];
        $eventId  = $payload['event']['id'] ?? null;
        $folio    = $payload['folio'] ?? ($payload['event']['folio'] ?? null);

        abort_if($eventId === null, 500, 'El evento se creó pero no devolvió id.');

        $this->link($conversation, 'event', (int) $eventId, $user);

        // Mensaje de traza en el hilo (va como mensaje normal del autor: así se
        // difunde por WebSocket y cuenta como no-leído igual que cualquier otro).
        $this->chat->sendMessage(
            $conversation,
            $user,
            "📋 Se levantó el evento {$folio} desde esta conversación.",
        );

        return response()->json([
            'message' => "Evento {$folio} creado y ligado a la conversación.",
            'data'    => ['event_id' => (int) $eventId, 'folio' => $folio],
        ], 201);
    }

    /** Liga la conversación a un evento / mantenimiento / sitio que ya existe. */
    public function store(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $this->authorizeParticipant($request, $conversation);

        $data = $request->validate([
            'linkable_type' => ['required', 'in:' . implode(',', ConversationLink::TYPES)],
            'linkable_id'   => ['required', 'integer'],
        ]);

        $target = $this->resolveTarget($data['linkable_type'], $data['linkable_id']);
        abort_if($target === null, 404, 'El elemento que quieres ligar no existe.');
        $this->authorizeTarget($user, $data['linkable_type'], $target);

        $this->link($conversation, $data['linkable_type'], $data['linkable_id'], $user);

        return response()->json([
            'message' => 'Conversación ligada.',
            'data'    => $this->serializeLinks($conversation),
        ], 201);
    }

    /** Quita una liga (no borra ni la conversación ni el evento). */
    public function destroy(Request $request, Conversation $conversation, ConversationLink $link): JsonResponse
    {
        $this->authorizeParticipant($request, $conversation);
        abort_unless($link->conversation_id === $conversation->id, 404);

        $link->delete();

        return response()->json(['message' => 'Liga eliminada.']);
    }

    /**
     * Conversación del evento: la devuelve si ya existe, si no la crea como GRUPO
     * con la gente que ya está involucrada (quien lo reportó, a quién se asignó y
     * quien abre el chat), y la deja ligada.
     */
    public function forEvent(Request $request, Event $event): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('events.view'), 403, 'No tienes acceso a este evento.');

        return $this->conversationFor(
            $user,
            'event',
            $event->id,
            'Evento ' . ($event->folio ?? $event->id),
            collect([$event->creator, $event->assignee]),
        );
    }

    /**
     * Conversación del mantenimiento. Mismo contrato que la del evento; los
     * involucrados aquí son quien lo creó y los ingenieros asignados.
     */
    public function forMaintenance(Request $request, Maintenance $maintenance): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('maintenances.view'), 403, 'No tienes acceso a este mantenimiento.');

        $maintenance->loadMissing(['site', 'system', 'engineers', 'creator']);

        $nombre = 'Mantenimiento '
            . ($maintenance->site?->name ? $maintenance->site->name . ' · ' : '')
            . ($maintenance->system?->label ?? '#' . $maintenance->id);

        return $this->conversationFor(
            $user,
            'maintenance',
            $maintenance->id,
            mb_strimwidth($nombre, 0, 150, '…'),
            collect([$maintenance->creator])->merge($maintenance->engineers),
        );
    }

    /**
     * Obtiene o crea la conversación ligada a un objeto de la operación.
     *
     * @param  \Illuminate\Support\Collection<int,User|null>  $involved  gente ya
     *         relacionada con el objeto; se filtra por ChatScope antes de meterla.
     */
    private function conversationFor(
        User $user,
        string $type,
        int $id,
        string $nombre,
        $involved,
    ): JsonResponse {
        abort_unless($user->can('chat.use'), 403, 'No tienes acceso al chat.');

        $existing = ConversationLink::where('linkable_type', $type)
            ->where('linkable_id', $id)
            ->with('conversation')
            ->first();

        if ($existing?->conversation) {
            $conversation = $existing->conversation;

            // Quien abre el chat del evento y no estaba, entra: es el punto del chat
            // de evento (que la gente se sume conforme se involucra).
            if (! $conversation->hasActiveParticipant($user->id)) {
                $this->chat->ensureActiveParticipants($conversation, collect([$user]));
            }

            return response()->json(['data' => ['conversation_id' => $conversation->id, 'created' => false]]);
        }

        // Participantes iniciales: solo los que REALMENTE pueden conversar con quien
        // abre (ChatScope), para no colar a alguien de otro cliente por la puerta de atrás.
        $candidates = collect([$user])->merge($involved)
            ->filter()
            ->unique('id')
            ->filter(fn (User $u) => $u->id === $user->id || ChatScope::canContact($user, $u))
            ->values();

        $conversation = DB::transaction(function () use ($user, $candidates, $type, $id, $nombre) {
            $clientId = ChatScope::resolveConversationClient($candidates);

            $conversation = Conversation::create([
                'type'       => 'group',
                'name'       => $nombre,
                'created_by' => $user->id,
                'client_id'  => $clientId === false ? null : $clientId,
            ]);

            $this->chat->ensureActiveParticipants($conversation, $candidates, adminIds: [$user->id]);
            $this->link($conversation, $type, $id, $user);

            return $conversation;
        });

        return response()->json(['data' => ['conversation_id' => $conversation->id, 'created' => true]], 201);
    }

    /** Alta idempotente de la liga (el índice único ya lo impide por duplicado). */
    private function link(Conversation $conversation, string $type, int $id, User $user): void
    {
        ConversationLink::firstOrCreate(
            ['conversation_id' => $conversation->id, 'linkable_type' => $type, 'linkable_id' => $id],
            ['created_by' => $user->id],
        );
    }

    private function resolveTarget(string $type, int $id): Event|Maintenance|Site|null
    {
        return match ($type) {
            'event'       => Event::find($id),
            'maintenance' => Maintenance::find($id),
            'site'        => Site::find($id),
            default       => null,
        };
    }

    /** No se liga a algo que el usuario no puede ver. */
    private function authorizeTarget(User $user, string $type, Event|Maintenance|Site $target): void
    {
        $permission = match ($type) {
            'event'       => 'events.view',
            'maintenance' => 'maintenances.view',
            'site'        => 'sites.view',
        };

        abort_unless($user->can($permission), 403, 'No tienes acceso a ese elemento.');
    }

    /** @return array<int,array<string,mixed>> */
    private function serializeLinks(Conversation $conversation): array
    {
        return $conversation->links()->get()->map(function (ConversationLink $link) {
            $target = $link->linkable();

            return [
                'id'    => $link->id,
                'type'  => $link->linkable_type,
                'ref_id' => $link->linkable_id,
                'label' => match (true) {
                    $target instanceof Event       => 'Evento ' . ($target->folio ?? $target->id),
                    $target instanceof Maintenance => 'Mantenimiento '
                        . ($target->site?->name ?? '#' . $target->id),
                    $target instanceof Site        => $target->name,
                    default                        => 'Elemento eliminado',
                },
                'exists' => $target !== null,
            ];
        })->values()->all();
    }

    private function authorizeParticipant(Request $request, Conversation $conversation): User
    {
        $user = $request->user();

        if (! $conversation->hasActiveParticipant($user->id)) {
            abort(403, 'No participas en esta conversación.');
        }

        return $user;
    }
}
