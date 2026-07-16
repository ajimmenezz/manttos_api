<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\CaptureContact;
use App\Models\CaptureConversation;
use App\Models\User;
use App\Services\Capture\InboundHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Chat de asistente DENTRO de la app: el usuario autenticado conversa con el mismo
 * agente de captación (levanta tickets / consulta estatus) por HTTP, reusando todo
 * el pipeline (CaptureAgent + EventCreator). Al ser un canal `inapp`, la conversación
 * también aparece en la Bandeja, así que un humano puede tomarla. Los límites por
 * permisos/sitios/clientes salen del alcance del propio usuario (evento atribuido a él).
 */
class AppChatController extends Controller
{
    public function __construct(private InboundHandler $inbound) {}

    /** Mi hilo completo. */
    public function index(Request $request): JsonResponse
    {
        $conv = $this->conversationFor($this->channel(), $request->user());

        return response()->json([
            'handling' => $conv?->handling ?? 'ai',
            'messages' => $conv ? $this->threadOf($conv) : [],
        ]);
    }

    /** Mando un mensaje: corre el agente y me regresa el hilo actualizado. */
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'body'              => 'required|string|max:4000',
            'client_message_id' => 'nullable|string|max:64',
            'images'            => 'nullable|array|max:10',
            'images.*'          => 'string|max:1000',
        ]);

        $user    = $request->user();
        $channel = $this->channel();
        $extId   = 'user:' . $user->id;
        $msgId   = $request->filled('client_message_id') ? 'app:' . $data['client_message_id'] : null;
        $images  = array_values($data['images'] ?? []);

        // Reusa el pipeline de captación; knownUser = el usuario autenticado (identidad + alcance).
        $this->inbound->handle($channel, $extId, $user->name, $data['body'], $msgId, null, $user, $images);

        $conv = $this->conversationFor($channel, $user);

        return response()->json([
            'handling' => $conv?->handling ?? 'ai',
            'messages' => $conv ? $this->threadOf($conv) : [],
        ]);
    }

    /** Polling de mensajes nuevos (respuestas del agente humano que tomó la conversación). */
    public function poll(Request $request): JsonResponse
    {
        $conv = $this->conversationFor($this->channel(), $request->user());
        if (! $conv) {
            return response()->json(['handling' => 'ai', 'messages' => []]);
        }

        $after = (int) $request->query('after', 0);
        $msgs = $conv->messages()->when($after > 0, fn ($q) => $q->where('id', '>', $after))
            ->with('sender:id,name')->get();

        return response()->json([
            'handling' => $conv->handling,
            'messages' => $msgs->map(fn ($m) => $this->serialize($m))->all(),
        ]);
    }

    // ── Internos ─────────────────────────────────────────────────────

    /** Canal único de la app (se crea la primera vez). */
    private function channel(): Channel
    {
        return Channel::firstOrCreate(
            ['provider' => Channel::PROVIDER_INAPP],
            [
                'name'               => 'App',
                'client_id'          => null,
                'agent_name'         => 'Asistente',
                'ai_enabled'         => true,
                'is_active'          => true,
                'require_registered' => false,
            ],
        );
    }

    private function conversationFor(Channel $channel, User $user): ?CaptureConversation
    {
        $contact = CaptureContact::where('channel_id', $channel->id)
            ->where('external_id', 'user:' . $user->id)->first();
        if (! $contact) {
            return null;
        }
        return CaptureConversation::where('contact_id', $contact->id)->latest('id')->first();
    }

    private function threadOf(CaptureConversation $conv): array
    {
        return $conv->messages()->with('sender:id,name')->get()
            ->map(fn ($m) => $this->serialize($m))->all();
    }

    private function serialize($m): array
    {
        return [
            'id'          => $m->id,
            'direction'   => $m->direction,
            'body'        => $m->body,
            'payload'     => $m->payload,
            'sender_name' => optional($m->sender)->name,
            'created_at'  => $m->created_at,
        ];
    }
}
