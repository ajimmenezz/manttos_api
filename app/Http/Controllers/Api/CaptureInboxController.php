<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CaptureConversation;
use App\Models\CaptureMessage;
use App\Services\Telegram\TelegramClient;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bandeja de captación: hilos persistentes por contacto (todas las líneas), con
 * relevo humano. Un agente puede TOMAR una conversación (la IA calla) y escribir
 * como humano por el mismo canal, o DEVOLVERLA a la IA. Gateado por `channels.manage`.
 */
class CaptureInboxController extends Controller
{
    public function __construct(
        private TelegramClient $telegram,
        private WhatsAppClient $whatsapp,
    ) {}

    /** Lista de conversaciones (todas las líneas) para la bandeja. */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('channels.manage'), 403);

        $convs = CaptureConversation::query()
            ->where('is_simulation', false) // las conversaciones del simulador no van a la bandeja real
            ->with([
                'contact:id,name,external_id',
                'channel:id,name,provider',
                'event:id,folio',
                'assignedAgent:id,name',
            ])
            ->addSelect(['last_body' => CaptureMessage::query()
                ->select('body')
                ->whereColumn('conversation_id', 'capture_conversations.id')
                ->whereIn('direction', ['in', 'out', 'human'])
                ->orderByDesc('created_at')->limit(1),
            ])
            ->when($request->filled('channel_id'), fn ($q) => $q->where('channel_id', $request->channel_id))
            ->when($request->filled('handling'),   fn ($q) => $q->where('handling', $request->handling))
            ->when($request->boolean('unread'),    fn ($q) => $q->where('unread_count', '>', 0))
            ->when($request->boolean('with_event'), fn ($q) => $q->whereNotNull('event_id'))
            ->when($request->filled('search'), fn ($q) => $q->whereHas('contact', fn ($c) =>
                $c->where('name', 'ilike', "%{$request->search}%")
                  ->orWhere('external_id', 'ilike', "%{$request->search}%")))
            ->orderByDesc('last_message_at')->orderByDesc('id')
            ->limit(300)->get();

        return response()->json($convs);
    }

    /** Detalle de una conversación + mensajes; marca como leída. */
    public function show(Request $request, CaptureConversation $conversation): JsonResponse
    {
        abort_unless($request->user()->can('channels.manage'), 403);

        $conversation->load([
            'contact:id,name,external_id,client_id,site_id',
            'contact.client:id,name,short_name',
            'contact.site:id,name',
            'channel:id,name,provider',
            'event:id,folio',
            'assignedAgent:id,name',
        ]);

        $messages = $conversation->messages()->with('sender:id,name')->get()
            ->map(fn ($m) => [
                'id'          => $m->id,
                'direction'   => $m->direction,
                'body'        => $m->body,
                'payload'     => $m->payload,
                'sender_name' => optional($m->sender)->name,
                'created_at'  => $m->created_at,
            ]);

        // Marca leída al abrir.
        if ($conversation->unread_count > 0) {
            $conversation->update(['unread_count' => 0]);
        }

        return response()->json([
            'conversation' => $conversation,
            'messages'     => $messages,
        ]);
    }

    /** Envía un mensaje como humano por el canal; toma la conversación si aún no. */
    public function send(Request $request, CaptureConversation $conversation): JsonResponse
    {
        abort_unless($request->user()->can('channels.manage'), 403);

        $data = $request->validate(['body' => 'required|string|max:4000']);

        $channel = $conversation->channel;
        $contact = $conversation->contact;
        if (! $channel || ! $channel->is_active) {
            return response()->json(['message' => 'La línea no está activa.'], 422);
        }

        try {
            if ($channel->isInApp()) {
                $outId = null; // el chat de la app lo lee por polling; sin envío externo
            } elseif ($channel->isTelegram()) {
                $outId = $this->telegram->sendText($channel, $contact->external_id, $data['body']);
            } else {
                $outId = $this->whatsapp->sendText($channel, $contact->external_id, $data['body']);
            }
        } catch (\Throwable $e) {
            return response()->json(['message' => 'No se pudo enviar el mensaje: ' . $e->getMessage()], 422);
        }

        $message = CaptureMessage::create([
            'conversation_id'     => $conversation->id,
            'channel_id'          => $channel->id,
            'direction'           => 'human',
            'sender_user_id'      => $request->user()->id,
            'external_message_id' => $outId,
            'body'                => $data['body'],
            'created_at'          => now(),
        ]);

        // Enviar como humano implica tomar la conversación (la IA deja de responder).
        $conversation->update([
            'handling'          => 'human',
            'assigned_agent_id' => $conversation->assigned_agent_id ?: $request->user()->id,
            'last_message_at'   => now(),
        ]);

        $message->load('sender:id,name');

        return response()->json([
            'id'          => $message->id,
            'direction'   => $message->direction,
            'body'        => $message->body,
            'payload'     => $message->payload,
            'sender_name' => optional($message->sender)->name,
            'created_at'  => $message->created_at,
        ], 201);
    }

    /** Toma (human) o devuelve (ai) la conversación. */
    public function setHandling(Request $request, CaptureConversation $conversation): JsonResponse
    {
        abort_unless($request->user()->can('channels.manage'), 403);

        $data = $request->validate(['mode' => 'required|in:ai,human']);

        $conversation->update([
            'handling'          => $data['mode'],
            'assigned_agent_id' => $data['mode'] === 'human' ? $request->user()->id : null,
        ]);

        // Nota de sistema en el hilo (trazabilidad del relevo).
        CaptureMessage::create([
            'conversation_id' => $conversation->id,
            'channel_id'      => $conversation->channel_id,
            'direction'       => 'system',
            'sender_user_id'  => $request->user()->id,
            'body'            => $data['mode'] === 'human'
                ? $request->user()->name . ' tomó la conversación'
                : 'Conversación devuelta al agente IA',
            'payload'         => ['type' => 'handoff', 'mode' => $data['mode']],
            'created_at'      => now(),
        ]);

        return response()->json([
            'message'  => $data['mode'] === 'human' ? 'Tomaste la conversación.' : 'La conversación volvió al agente IA.',
            'handling' => $data['mode'],
        ]);
    }
}
