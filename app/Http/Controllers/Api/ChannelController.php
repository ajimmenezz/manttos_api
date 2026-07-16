<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\CaptureConversation;
use App\Services\Telegram\TelegramClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Alta y configuración de líneas de mensajería para captación de eventos.
 * Telegram: basta el bot token; se valida con getMe y se registra el webhook
 * automáticamente (o se usa long-polling en desarrollo). Gateado por
 * `channels.manage`.
 */
class ChannelController extends Controller
{
    public function __construct(private TelegramClient $telegram) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('channels.manage'), 403);

        $channels = Channel::with('client:id,name,short_name')
            ->where('provider', '!=', Channel::PROVIDER_INAPP) // el canal de la app no se configura como línea
            ->withCount(['conversations', 'conversations as events_count' => fn ($q) => $q->whereNotNull('event_id')])
            ->orderBy('name')->get();

        return response()->json($channels);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('channels.manage'), 403);

        $data = $this->validateData($request, true);

        $metadata = [];
        if ($data['provider'] === Channel::PROVIDER_TELEGRAM) {
            $bot = $this->telegram->getMe($data['access_token']);   // lanza si el token es inválido
            $metadata['tg_bot'] = ['id' => $bot['id'] ?? null, 'username' => $bot['username'] ?? null];
            $metadata['tg_webhook_secret'] = Str::random(40);
        }

        $channel = Channel::create([
            'name'                  => $data['name'],
            'provider'              => $data['provider'],
            'client_id'             => $data['client_id'] ?? null,
            'access_token'          => $data['access_token'] ?? null,
            'phone_number_id'       => $data['phone_number_id'] ?? null,
            'default_event_type_id' => $data['default_event_type_id'] ?? null,
            'default_system_id'     => $data['default_system_id'] ?? null,
            'created_by_user_id'    => $data['created_by_user_id'] ?? $request->user()->id,
            'agent_name'            => $data['agent_name'] ?? 'Asistente',
            'instructions'          => $data['instructions'] ?? null,
            'ai_enabled'            => $data['ai_enabled'] ?? true,
            'require_registered'    => $data['require_registered'] ?? false,
            'first_level_support'   => $data['first_level_support'] ?? 'off',
            'is_active'             => $data['is_active'] ?? true,
            'metadata'              => $metadata,
        ]);

        $notice = $this->registerWebhook($channel);

        return response()->json(['message' => 'Línea creada.', 'channel' => $channel->fresh('client'), 'notice' => $notice], 201);
    }

    public function update(Request $request, Channel $channel): JsonResponse
    {
        abort_unless($request->user()->can('channels.manage'), 403);

        $data = $this->validateData($request, false);
        $notice = null;

        // Si cambia el token de Telegram, re-validar y re-registrar el webhook.
        if (! empty($data['access_token']) && $channel->isTelegram()) {
            $bot = $this->telegram->getMe($data['access_token']);
            $meta = $channel->metadata ?? [];
            $meta['tg_bot'] = ['id' => $bot['id'] ?? null, 'username' => $bot['username'] ?? null];
            $meta['tg_webhook_secret'] = $meta['tg_webhook_secret'] ?? Str::random(40);
            $channel->metadata = $meta;
        }

        $channel->fill(collect($data)->except('access_token')->all());
        if (! empty($data['access_token'])) {
            $channel->access_token = $data['access_token'];
        }
        $channel->save();

        if (! empty($data['access_token']) && $channel->isTelegram()) {
            $notice = $this->registerWebhook($channel);
        }

        return response()->json(['message' => 'Línea actualizada.', 'channel' => $channel->fresh('client'), 'notice' => $notice]);
    }

    public function destroy(Request $request, Channel $channel): JsonResponse
    {
        abort_unless($request->user()->can('channels.manage'), 403);

        if ($channel->isTelegram() && $channel->token()) {
            try { $this->telegram->deleteWebhook($channel); } catch (\Throwable) { /* best-effort */ }
        }
        $channel->delete();

        return response()->json(['message' => 'Línea eliminada.']);
    }

    /** Verifica el token de la línea (getMe). */
    public function verifyToken(Request $request, Channel $channel): JsonResponse
    {
        abort_unless($request->user()->can('channels.manage'), 403);

        if (! $channel->isTelegram() || ! $channel->token()) {
            return response()->json(['ok' => false, 'message' => 'La línea no tiene token de Telegram.'], 422);
        }
        try {
            $bot = $this->telegram->getMe($channel->token());
            return response()->json(['ok' => true, 'bot' => ['id' => $bot['id'] ?? null, 'username' => $bot['username'] ?? null]]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /** Conversaciones de captación de una línea (tablero/trazabilidad). */
    public function conversations(Request $request, Channel $channel): JsonResponse
    {
        abort_unless($request->user()->can('channels.manage'), 403);

        $convs = $channel->conversations()
            ->with(['contact:id,name,external_id', 'event:id,folio'])
            ->withCount('messages')
            ->orderByDesc('last_message_at')->orderByDesc('id')
            ->limit(200)->get();

        return response()->json($convs);
    }

    /** Mensajes de una conversación (para revisar el intercambio). */
    public function conversationMessages(Request $request, Channel $channel, CaptureConversation $conversation): JsonResponse
    {
        abort_unless($request->user()->can('channels.manage'), 403);
        abort_unless($conversation->channel_id === $channel->id, 404);

        return response()->json($conversation->messages()->get(['id', 'direction', 'body', 'payload', 'created_at']));
    }

    // ── Internos ─────────────────────────────────────────────────────

    private function validateData(Request $request, bool $creating): array
    {
        return $request->validate([
            'name'                  => ($creating ? 'required' : 'sometimes') . '|string|max:120',
            'provider'              => ($creating ? 'required' : 'sometimes') . '|in:telegram,whatsapp',
            // Opcional: sin cliente, la línea atiende a varios y el agente lo deduce por el sitio.
            'client_id'             => 'nullable|exists:clients,id',
            // En alta de Telegram el token es obligatorio; en edición es opcional (solo si se cambia).
            'access_token'          => ($creating && $request->input('provider') === 'telegram' ? 'required' : 'nullable') . '|string|max:512',
            'phone_number_id'       => 'nullable|string|max:120',
            'default_event_type_id' => 'nullable|exists:event_types,id',
            'default_system_id'     => 'nullable|exists:catalogs,id',
            'created_by_user_id'    => 'nullable|exists:users,id',
            'agent_name'            => 'nullable|string|max:120',
            'instructions'          => 'nullable|string|max:5000',
            'ai_enabled'            => 'boolean',
            'require_registered'    => 'boolean',
            'first_level_support'   => 'nullable|in:off,assist,deflect',
            'is_active'             => 'boolean',
        ]);
    }

    /**
     * Registra el webhook de Telegram si la app es pública (https). En localhost/http
     * devuelve un aviso: en dev se usa `php artisan telegram:poll`.
     */
    private function registerWebhook(Channel $channel): ?string
    {
        if (! $channel->isTelegram() || ! $channel->token()) {
            return null;
        }

        $appUrl = rtrim((string) config('app.url'), '/');
        $secret = $channel->metadata['tg_webhook_secret'] ?? null;

        if (! str_starts_with($appUrl, 'https://') || str_contains($appUrl, 'localhost') || str_contains($appUrl, '127.0.0.1')) {
            return 'La app no es pública (https): usa "php artisan telegram:poll" para recibir mensajes en desarrollo.';
        }

        $url = "{$appUrl}/api/telegram/webhook/{$channel->id}";
        try {
            $this->telegram->setWebhook($channel, $url, (string) $secret);
            $meta = $channel->metadata ?? [];
            $meta['tg_webhook_url'] = $url;
            $channel->update(['metadata' => $meta]);
            return null;
        } catch (\Throwable $e) {
            return 'No se pudo registrar el webhook automáticamente: ' . $e->getMessage();
        }
    }
}
