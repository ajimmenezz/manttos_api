<?php

namespace App\Services\Capture;

use App\Models\Channel;
use App\Models\CaptureContact;
use App\Models\CaptureConversation;
use App\Models\CaptureMessage;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Support\Facades\Log;

/**
 * Pipeline de entrada (recortado) de la captación: normaliza el mensaje, mantiene
 * la conversación por remitente, corre el agente y responde por el mismo canal;
 * cuando el agente reúne los datos, levanta el evento al pool.
 */
class InboundHandler
{
    public function __construct(
        private CaptureAgent $agent,
        private EventCreator $creator,
        private TelegramClient $telegram,
        private WhatsAppClient $whatsapp,
    ) {}

    public function handle(Channel $channel, string $externalId, ?string $name, string $text, ?string $externalMessageId, ?string $username = null, ?User $knownUser = null): void
    {
        if (! $channel->is_active) {
            return;
        }

        $contact = $this->resolveContact($channel, $externalId, $name, $username, $knownUser);

        // Idempotencia: no reprocesar el mismo mensaje del proveedor.
        if ($externalMessageId && CaptureMessage::where('channel_id', $channel->id)
                ->where('external_message_id', $externalMessageId)->exists()) {
            return;
        }

        // Hilo PERSISTENTE por contacto: reutiliza el existente (no importa que ya
        // haya generado eventos); solo crea uno si el contacto es nuevo.
        $conversation = CaptureConversation::where('contact_id', $contact->id)
            ->latest('id')->first()
            ?? CaptureConversation::create([
                'channel_id' => $channel->id,
                'contact_id' => $contact->id,
                'status'     => 'open',
                'handling'   => 'ai',
            ]);

        // Guarda el entrante + marca no leído para la bandeja.
        CaptureMessage::create([
            'conversation_id'     => $conversation->id,
            'channel_id'          => $channel->id,
            'direction'           => 'in',
            'external_message_id' => $externalMessageId,
            'body'                => $text,
            'created_at'          => now(),
        ]);
        $conversation->update([
            'last_message_at' => now(),
            'last_inbound_at' => now(),
            'unread_count'    => (int) $conversation->unread_count + 1,
        ]);

        // Un humano tomó la conversación (o la IA de la línea está apagada): NO
        // auto-responder. El mensaje queda en la bandeja para que el humano conteste.
        if ($conversation->isHumanHandled() || ! $channel->ai_enabled) {
            return;
        }

        // Línea que solo atiende a personas registradas/reconocidas: si no se identifica
        // (ni usuario vinculado ni contacto etiquetado a cliente/sitio), no capta ni informa.
        $identified = $contact->user_id || $contact->client_id || $contact->site_id;
        if ($channel->require_registered && ! $identified) {
            $reply = 'Hola 👋 Para atender tu solicitud necesito que estés registrado. '
                . 'Por favor contacta a tu administrador para darte de alta y con gusto te ayudo.';
            $outId = $this->send($channel, $externalId, $reply);
            CaptureMessage::create([
                'conversation_id'     => $conversation->id,
                'channel_id'          => $channel->id,
                'direction'           => 'out',
                'external_message_id' => $outId,
                'body'                => $reply,
                'created_at'          => now(),
            ]);
            return;
        }

        // Agente: decide y arma la respuesta.
        $decision = $this->agent->respond($conversation->fresh('messages'));
        $reply = $decision['reply'];

        // ¿Listo? → levantar el evento al pool. Idempotencia POR REPORTE (firma
        // sitio+sistema+descripción): repetir la confirmación no duplica; una falla
        // distinta sí genera un evento nuevo dentro del mismo hilo.
        $ticketKey  = $this->ticketKey($conversation->id, $decision);
        $lastKey    = $conversation->state['last_ticket_key'] ?? null;
        $createdKey = $lastKey; // se actualiza solo si se crea un evento en este turno

        // ── Dispositivo: resolver, o pedir que elija entre candidatos ─────────
        $deviceId       = null;
        $deferForDevice = false;
        $keepCandidates = null; // se persiste solo si quedamos esperando la elección

        if (! empty($decision['device_id']) && ! empty($conversation->state['device_candidates'])) {
            // La persona eligió uno de los candidatos ofrecidos antes (validado por el agente).
            $deviceId = (int) $decision['device_id'];
        } elseif (! empty($decision['device_hint']) && $decision['ready'] && $decision['site_id'] && $decision['system_id']) {
            $cands = $this->creator->deviceCandidates((int) $decision['site_id'], (int) $decision['system_id'], (string) $decision['device_hint']);
            if (count($cands) === 1) {
                $deviceId = (int) $cands[0]['id'];
            } elseif (count($cands) >= 2) {
                // Varios equipos parecidos: ofrece opciones y espera (aún no crea el evento).
                $deferForDevice = true;
                $keepCandidates = $cands;
                $names = collect($cands)->pluck('name')->implode(', ');
                $reply = "Encontré varios equipos parecidos a \"{$decision['device_hint']}\": {$names}. ¿Cuál es? "
                    . 'Dime el nombre o responde "ninguno" para registrarlo sin equipo.';
            }
            // 0 candidatos → se registra sin dispositivo (deviceId = null)
        }

        // Soporte "resolver primero": si el cliente confirmó que se solucionó con la
        // guía del manual, NO se levanta el evento (el agente ya cierra con cordialidad).
        if (! $deferForDevice && empty($decision['resolved']) && $decision['ready'] && $decision['site_id'] && $decision['system_id'] && $decision['description'] && $ticketKey !== $lastKey) {
            $result = $this->creator->create(
                $channel,
                (int) $decision['site_id'],
                (int) $decision['system_id'],
                (string) $decision['description'],
                $decision['priority'] ?? null,
                $ticketKey, // idempotencia del alta
                $conversation->contact->user, // solicitante reconocido (si lo hay)
                $deviceId, // dispositivo resuelto (o null)
            );

            if ($result['ok']) {
                $folio   = $result['folio'] ?? null;
                $eventId = $result['event_id'] ?? null;

                // Checkpoint en el hilo (no se envía por el canal).
                CaptureMessage::create([
                    'conversation_id' => $conversation->id,
                    'channel_id'      => $channel->id,
                    'direction'       => 'system',
                    'body'            => $folio ? "Evento {$folio} generado" : 'Evento generado',
                    'payload'         => ['type' => 'event_created', 'event_id' => $eventId, 'folio' => $folio],
                    'created_at'      => now(),
                ]);

                $conversation->event_id = $eventId; // último evento del hilo (referencia rápida)
                $createdKey = $ticketKey;
                $reply = $folio
                    ? "✅ Listo, tu reporte quedó registrado con folio {$folio}. Un técnico lo atenderá pronto."
                    : '✅ Listo, tu reporte quedó registrado. Un técnico lo atenderá pronto.';
            } else {
                $reply = 'Tuve un problema al registrar tu reporte; uno de nuestros agentes de solución lo revisará. Detalle: ' . $result['error'];
            }
        }

        // Persistir estado acumulado (trazabilidad) + memoria del agente. Los candidatos
        // de dispositivo solo se conservan si seguimos esperando que la persona elija.
        // Conserva el último sitio/sistema conocido del hilo si este turno vino sin ellos
        // (hilo persistente): así el soporte de 1er nivel puede recuperar el manual del
        // sistema aunque el mensaje de seguimiento no lo repita.
        $state = [
            'is_ticket'       => $decision['is_ticket'],
            'site_id'         => $decision['site_id'] ?? ($conversation->state['site_id'] ?? null),
            'system_id'       => $decision['system_id'] ?? ($conversation->state['system_id'] ?? null),
            'description'     => $decision['description'],
            'priority'        => $decision['priority'],
            'last_ticket_key' => $createdKey,
        ];
        if ($keepCandidates) {
            $state['device_candidates'] = $keepCandidates;
        }
        $conversation->fill([
            'state'           => $state,
            'last_message_at' => now(),
        ]);
        if (! empty($decision['memory'])) {
            $conversation->context_summary = $decision['memory'];
        }
        $conversation->save();

        // Responder por el mismo canal y guardar el saliente.
        $outId = $this->send($channel, $externalId, $reply);
        CaptureMessage::create([
            'conversation_id'     => $conversation->id,
            'channel_id'          => $channel->id,
            'direction'           => 'out',
            'external_message_id' => $outId,
            'body'                => $reply,
            'created_at'          => now(),
        ]);
    }

    /**
     * Resuelve (o crea) el contacto. Adopta un pre-registro cuando aplica: por
     * teléfono/wa_id (WhatsApp, ya casa por external_id) o por usuario de Telegram
     * (pre-registro sin chat_id que se completa al primer mensaje).
     */
    private function resolveContact(Channel $channel, string $externalId, ?string $name, ?string $username, ?User $knownUser = null): CaptureContact
    {
        $username = $username ? ltrim(strtolower(trim($username)), '@') : null;

        // 1) Contacto ya conocido por su identificador del canal.
        $contact = CaptureContact::where('channel_id', $channel->id)
            ->where('external_id', $externalId)->first();

        // 2) Pre-registro de Telegram por usuario (aún sin chat_id).
        if (! $contact && $username) {
            $contact = CaptureContact::where('channel_id', $channel->id)
                ->whereNull('external_id')->where('username', $username)->first();
            if ($contact) {
                $contact->external_id = $externalId;
                $contact->pre_registered = false;
            }
        }

        // 3) Nuevo.
        if (! $contact) {
            $contact = new CaptureContact([
                'channel_id' => $channel->id,
                'external_id' => $externalId,
            ]);
        }

        if ($name && $contact->name !== $name) $contact->name = $name;
        if ($username && $contact->username !== $username) $contact->username = $username;

        // Reconoce a QUIÉN escribe. En el chat de la app el usuario viene autenticado
        // (knownUser); en Telegram/WhatsApp se empareja por su identidad de mensajería.
        $user = $knownUser ?: $this->matchUser($channel, $externalId, $username);
        if ($user) {
            $contact->user_id = $user->id;
            if (! $contact->name) $contact->name = $user->name;
        }

        $contact->save();

        return $contact;
    }

    /** Usuario registrado cuya identidad de mensajería coincide con el remitente. */
    private function matchUser(Channel $channel, string $externalId, ?string $username): ?User
    {
        if ($channel->isTelegram() && $username) {
            return User::where('is_active', true)->where('telegram_username', $username)->first();
        }
        if ($channel->isWhatsApp()) {
            $phone = preg_replace('/\D+/', '', $externalId);
            if ($phone) {
                return User::where('is_active', true)->where('whatsapp_number', $phone)->first();
            }
        }
        return null;
    }

    /** Llave de idempotencia por reporte: hilo + firma (sitio|sistema|descripción). ≤64 chars. */
    private function ticketKey(int $conversationId, array $decision): string
    {
        $sig = ($decision['site_id'] ?? '') . '|' . ($decision['system_id'] ?? '') . '|' . ($decision['description'] ?? '');
        return 'cap-' . $conversationId . '-' . substr(sha1($sig), 0, 20);
    }

    /** Envía una respuesta por el canal correspondiente. */
    private function send(Channel $channel, string $externalId, string $text): ?string
    {
        // Canal de la app: no hay envío externo; la respuesta ya quedó guardada y la
        // app la lee por polling / en la misma respuesta HTTP.
        if ($channel->isInApp()) {
            return null;
        }
        try {
            if ($channel->isTelegram()) {
                return $this->telegram->sendText($channel, $externalId, $text);
            }
            if ($channel->isWhatsApp()) {
                return $this->whatsapp->sendText($channel, $externalId, $text);
            }
        } catch (\Throwable $e) {
            Log::warning('Captación: no se pudo enviar respuesta', ['channel' => $channel->id, 'error' => $e->getMessage()]);
        }

        return null;
    }
}
