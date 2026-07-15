<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\CaptureContact;
use App\Models\CaptureConversation;
use App\Models\User;
use App\Services\Capture\SimulatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Simulador del agente de captación (solo `channels.manage`): permite probar la
 * conversación como si fuera un solicitante escribiendo por Telegram/cualquier
 * canal, con el agente real, el conocimiento y el soporte de 1er nivel. La creación
 * de tickets es dry-run por defecto. Cada tester tiene UNA sesión sandbox activa
 * (contacto con external_id `sim:{actor}` + conversación `is_simulation`).
 */
class CaptureSimulatorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('channels.manage'), 403);

        return response()->json([
            'session'  => $this->serializeSession($this->currentConversation($request->user())),
            'channels' => Channel::orderBy('name')->get()
                ->map(fn ($c) => [
                    'id' => $c->id, 'name' => $c->name, 'provider' => $c->messagingProvider(),
                    'client' => optional($c->client)->short_name ?: optional($c->client)->name,
                    'ai_enabled' => (bool) $c->ai_enabled,
                    'require_registered' => (bool) $c->require_registered,
                    'first_level_support' => $c->supportMode(),
                ]),
        ]);
    }

    /** Inicia/reinicia una sesión de simulación con una línea + identidad. */
    public function start(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('channels.manage'), 403);

        $data = $request->validate([
            'channel_id'      => 'required|exists:channels,id',
            'identity_mode'   => 'required|in:user,anon',
            'user_id'         => 'nullable|exists:users,id',
            'name'            => 'nullable|string|max:120',
            'client_id'       => 'nullable|exists:clients,id',
            'site_id'         => 'nullable|exists:sites,id',
        ]);

        $this->clearSession($request->user()); // una sesión a la vez

        $channel = Channel::findOrFail($data['channel_id']);
        $ext = 'sim:' . $request->user()->id;

        $contact = new CaptureContact([
            'channel_id'  => $channel->id,
            'external_id' => $ext,
        ]);
        if ($data['identity_mode'] === 'user' && ! empty($data['user_id'])) {
            $u = User::find($data['user_id']);
            $contact->user_id = $u?->id;
            $contact->name = $u?->name ?: 'Solicitante (sim)';
        } else {
            $contact->name = $data['name'] ?: 'Contacto de prueba';
            $contact->client_id = $data['client_id'] ?? null;
            $contact->site_id = $data['site_id'] ?? null;
        }
        $contact->save();

        $conv = CaptureConversation::create([
            'channel_id'    => $channel->id,
            'contact_id'    => $contact->id,
            'status'        => 'open',
            'handling'      => 'ai',
            'is_simulation' => true,
        ]);

        return response()->json(['session' => $this->serializeSession($conv->fresh(['channel', 'contact']))]);
    }

    /** Un turno del simulador. */
    public function message(Request $request, SimulatorService $sim): JsonResponse
    {
        abort_unless($request->user()->can('channels.manage'), 403);

        $data = $request->validate([
            'message'     => 'required|string|max:2000',
            'create_real' => 'boolean',
        ]);

        $conv = $this->currentConversation($request->user());
        abort_if(! $conv, 422, 'No hay una sesión de simulación activa. Inicia una.');

        $result = $sim->turn($conv, $data['message'], (bool) ($data['create_real'] ?? false));

        return response()->json([
            'result'   => $result,
            'messages' => $this->messages($conv),
        ]);
    }

    /** Termina la sesión (borra la conversación sandbox). */
    public function reset(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('channels.manage'), 403);
        $this->clearSession($request->user());
        return response()->json(['ok' => true]);
    }

    // ── Internos ─────────────────────────────────────────────────────

    private function currentConversation(User $actor): ?CaptureConversation
    {
        $contact = CaptureContact::where('external_id', 'sim:' . $actor->id)->latest('id')->first();
        if (! $contact) return null;
        return CaptureConversation::where('contact_id', $contact->id)->where('is_simulation', true)
            ->latest('id')->first()?->load('channel', 'contact');
    }

    private function clearSession(User $actor): void
    {
        $contacts = CaptureContact::where('external_id', 'sim:' . $actor->id)->get();
        foreach ($contacts as $c) {
            $convs = CaptureConversation::where('contact_id', $c->id)->get();
            foreach ($convs as $conv) {
                $conv->messages()->delete();
                $conv->delete();
            }
            $c->delete();
        }
    }

    private function serializeSession(?CaptureConversation $conv): ?array
    {
        if (! $conv) return null;
        $contact = $conv->contact;
        return [
            'conversation_id' => $conv->id,
            'channel' => [
                'id' => $conv->channel->id, 'name' => $conv->channel->name,
                'provider' => $conv->channel->messagingProvider(),
                'first_level_support' => $conv->channel->supportMode(),
                'require_registered' => (bool) $conv->channel->require_registered,
            ],
            'identity' => [
                'name'      => $contact->name,
                'user_id'   => $contact->user_id,
                'client_id' => $contact->client_id,
                'site_id'   => $contact->site_id,
            ],
            'messages' => $this->messages($conv),
        ];
    }

    private function messages(CaptureConversation $conv): array
    {
        return $conv->messages()->get(['id', 'direction', 'body', 'payload', 'created_at'])
            ->map(fn ($m) => [
                'id' => $m->id, 'direction' => $m->direction, 'body' => $m->body,
                'payload' => $m->payload, 'created_at' => $m->created_at,
            ])->all();
    }
}
