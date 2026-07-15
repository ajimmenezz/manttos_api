<?php

namespace App\Services\Capture;

use App\Models\Catalog;
use App\Models\Channel;
use App\Models\CaptureConversation;
use App\Models\Directory;
use App\Models\Site;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Chat\ChatProviderFactory;
use App\Services\Ai\Rag\RagService;
use App\Services\Ai\Tools\ToolRegistry;
use Illuminate\Support\Facades\Http;

/**
 * Agente de captación: dado el hilo de una conversación entrante, identifica si
 * es una solicitud de servicio y reúne los parámetros del evento (sitio, sistema,
 * descripción, prioridad) contra los catálogos REALES del cliente de la línea.
 * Devuelve la decisión + la respuesta a enviar. Reutiliza el stack provider-
 * agnóstico del asistente interno (AiSettings + ChatProviderFactory), sin RAG.
 */
class CaptureAgent
{
    /** Cuántos mensajes recientes se mandan al modelo; lo anterior vive en la memoria. */
    private const HISTORY_LIMIT = 40;

    /**
     * @return array{
     *   ok:bool, is_ticket:bool, site_id:?int, system_id:?int, description:?string,
     *   priority:?string, ready:bool, reply:string, memory:?string,
     *   usage:array{input:int,output:int}
     * }
     */
    public function respond(CaptureConversation $conversation, string $usageSource = 'captacion'): array
    {
        $channel = $conversation->channel;
        $resolved = AiSettings::resolved();

        // IA no configurada → respuesta de cortesía (no rompe la captación).
        if (! $resolved['enabled'] || empty($resolved['model']) || (! $resolved['local'] && empty($resolved['api_key']))) {
            return $this->fallback('Gracias por tu mensaje. En breve te contactará uno de nuestros agentes de solución.');
        }

        $contact = $conversation->contact;
        [$sites, $systems] = $this->catalog($channel, $contact);
        $messages = $this->history($conversation);
        $events = $this->contactEvents($contact);
        // Candidatos de dispositivo pendientes de elección (de una pregunta previa).
        $deviceCandidates = $conversation->state['device_candidates'] ?? [];

        // Soporte de 1er nivel (RAG por sistema): recupera conocimiento del manual del
        // sistema en curso para que el agente pueda guiar antes/junto con el ticket.
        $supportMode = $channel->supportMode();
        $knowledge = $this->knowledgeFor($conversation, $channel, $contact, $sites, $systems, $supportMode);

        $system = $this->systemPrompt($channel, $contact, $sites, $systems, $events, $deviceCandidates, (string) $conversation->context_summary, $knowledge, $supportMode);
        try {
            [$content, $usage] = $this->complete($messages, $system, $resolved);
        } catch (\Throwable $e) {
            return $this->fallback('Estamos teniendo un problema para procesar tu mensaje. Intenta de nuevo en un momento.', ['input' => 0, 'output' => 0]);
        }

        $decision = $this->parseJson((string) $content);

        // Registra el consumo del agente (para el Registro IA / control de costo).
        \App\Services\Ai\AiUsageLogger::log($usageSource, $resolved, $usage, [
            'user_id' => optional($conversation->contact)->user_id,
            'prompt'  => $this->lastInbound($conversation),
            'reply'   => is_array($decision) ? ($decision['reply'] ?? null) : (string) $content,
        ]);

        if ($decision === null) {
            return $this->fallback('¿Me confirmas qué necesitas reportar?', $usage);
        }

        $siteId   = $this->validId($decision['site_id'] ?? null, $sites->pluck('id'));
        $systemId = $this->validId($decision['system_id'] ?? null, $this->systemIdsForSite($sites, $siteId));

        return [
            'ok'          => true,
            'is_ticket'   => (bool) ($decision['is_ticket'] ?? false),
            'site_id'     => $siteId,
            'system_id'   => $systemId,
            'description' => isset($decision['description']) ? trim((string) $decision['description']) ?: null : null,
            'priority'    => $this->validPriority($decision['priority'] ?? null),
            // Identificador de equipo/dispositivo mencionado (serie, nombre, ubicación) para
            // intentar ligar el evento a un dispositivo del sitio (se resuelve en el servidor).
            'device_hint' => isset($decision['device_hint']) ? (trim((string) $decision['device_hint']) ?: null) : null,
            // ID de dispositivo elegido de la lista de CANDIDATOS (cuando se ofrecieron opciones).
            'device_id'   => $this->validId($decision['device_id'] ?? null, collect($deviceCandidates)->pluck('id')),
            // Solo listo si hay lo mínimo Y el modelo lo marcó (tras confirmar con el usuario).
            'ready'       => (bool) ($decision['ready'] ?? false) && $siteId && $systemId && ! empty($decision['description']),
            'reply'       => trim((string) ($decision['reply'] ?? '')) ?: 'Cuéntame, ¿qué necesitas reportar?',
            // Memoria acumulada del contacto/contexto para no re-preguntar en el mismo hilo.
            'memory'      => isset($decision['memory']) ? (trim((string) $decision['memory']) ?: null) : null,
            // Soporte 1er nivel: el cliente confirmó que se resolvió con la guía (no crear ticket).
            'resolved'    => (bool) ($decision['resolved'] ?? false),
            'usage'       => $usage,
        ];
    }

    /**
     * Llama al modelo pidiendo SALIDA JSON. Para APIs compatibles con OpenAI hace
     * una llamada limpia SIN tools y con response_format=json_object (JSON garantizado,
     * sin que las herramientas del asistente distraigan al modelo). Para Anthropic usa
     * su proveedor (que prefija "{" para forzar JSON). Ollama local: sin response_format
     * (no lo soporta), confía en la instrucción + el parser tolerante.
     *
     * @return array{0:string,1:array{input:int,output:int}}
     */
    private function complete(array $messages, string $system, array $resolved): array
    {
        if (($resolved['api_style'] ?? 'openai') === 'anthropic') {
            $provider = ChatProviderFactory::make($resolved, ToolRegistry::make());
            $res = $provider->chat($messages, [], $system);
            return [(string) ($res['content'] ?? ''), [
                'input'  => (int) ($res['usage']['input'] ?? 0),
                'output' => (int) ($res['usage']['output'] ?? 0),
            ]];
        }

        $wire = [['role' => 'system', 'content' => $system]];
        foreach ($messages as $m) {
            $wire[] = ['role' => $m['role'] === 'assistant' ? 'assistant' : 'user', 'content' => (string) $m['content']];
        }

        $payload = [
            'model'       => $resolved['model'],
            'messages'    => $wire,
            'temperature' => 0.2,
            'max_tokens'  => 800,
            'stream'      => false,
        ];
        if (empty($resolved['local'])) {
            $payload['response_format'] = ['type' => 'json_object']; // OpenAI/DeepSeek; Ollama no lo soporta
        }

        $req = Http::baseUrl(rtrim((string) ($resolved['base_url'] ?: 'https://api.openai.com/v1'), '/'))
            ->timeout(60)->acceptJson();
        if (! empty($resolved['api_key'])) {
            $req = $req->withToken($resolved['api_key']);
        }

        $res = $req->post('/chat/completions', $payload);
        if ($res->failed()) {
            throw new \RuntimeException('IA (' . $res->status() . '): ' . $res->body());
        }

        $usage = $res->json('usage') ?? [];
        return [
            (string) ($res->json('choices.0.message.content') ?? ''),
            ['input' => (int) ($usage['prompt_tokens'] ?? 0), 'output' => (int) ($usage['completion_tokens'] ?? 0)],
        ];
    }

    // ── Catálogo del cliente ─────────────────────────────────────────

    /**
     * Sitios que la conversación puede atender + sistemas. Prioridad de alcance:
     * (1) contacto etiquetado a un SITIO → solo ese sitio; (2) contacto etiquetado a
     * un CLIENTE → sus sitios; (3) línea dedicada a un cliente → sus sitios;
     * (4) multi-cliente → sitios de TODOS los clientes activos, cada uno etiquetado
     * con su cliente para que el agente deduzca el cliente a partir del sitio.
     *
     * @return array{0:\Illuminate\Support\Collection,1:\Illuminate\Support\Collection} [sites, systems]
     */
    private function catalog(Channel $channel, ?\App\Models\CaptureContact $contact = null): array
    {
        $siteQuery = Site::query()->where('is_active', true)->with('client:id,name,short_name');
        if ($contact && $contact->user_id) {
            // Usuario RECONOCIDO: manda su alcance real (el evento se crea como él y
            // debe poder usar el sitio). Los roles globales (superadmin/admin) ven todos;
            // los demás, solo los sitios a los que tienen acceso. La etiqueta del contacto
            // NO aplica aquí (evita ofrecer sitios que el usuario no puede usar).
            $u = $contact->user;
            if ($u && $u->hasAnyRole(['superadmin', 'admin'])) {
                $siteQuery->whereHas('client');
            } else {
                $ids = $this->accessibleSiteIds($u);
                $siteQuery->whereIn('id', $ids->isEmpty() ? [0] : $ids->all());
            }
        } elseif ($contact && $contact->site_id) {
            $siteQuery->where('id', $contact->site_id);
        } elseif ($contact && $contact->client_id) {
            $siteQuery->where('client_id', $contact->client_id);
        } elseif ($channel->client_id) {
            $siteQuery->where('client_id', $channel->client_id);
        } else {
            $siteQuery->whereHas('client'); // excluye sitios de clientes archivados
        }
        $sites = $siteQuery->orderBy('name')->get(['id', 'name', 'client_id']);

        $dirRows = Directory::whereIn('site_id', $sites->pluck('id'))->where('is_active', true)
            ->get(['site_id', 'catalog_id']);

        $bySite = $dirRows->groupBy('site_id')->map(fn ($g) => $g->pluck('catalog_id')->unique()->values());

        $sitesOut = $sites->map(fn ($s) => [
            'id'         => $s->id,
            'name'       => $s->name,
            'client'     => optional($s->client)->short_name ?: optional($s->client)->name,
            'system_ids' => $bySite->get($s->id, collect())->all(),
        ]);

        $systems = Catalog::whereIn('id', $dirRows->pluck('catalog_id')->unique())
            ->where('is_active', true)->orderBy('label')->get(['id', 'label'])
            ->map(fn ($c) => ['id' => $c->id, 'label' => $c->label]);

        return [$sitesOut, $systems];
    }

    private function systemIdsForSite($sites, ?int $siteId)
    {
        if (! $siteId) return collect();
        $site = collect($sites)->firstWhere('id', $siteId);
        return collect($site['system_ids'] ?? []);
    }

    /** IDs de sitios a los que un usuario tiene alcance (admin de cliente/sitio o ingeniero). */
    private function accessibleSiteIds(?\App\Models\User $user)
    {
        if (! $user) return collect();

        $clientIds = $user->clientsAsAdmin()->pluck('clients.id')
            ->merge($user->clientsAsEngineer()->pluck('clients.id'))
            ->merge($user->solicitanteClients()->pluck('clients.id'))->unique();

        return Site::whereIn('client_id', $clientIds)->pluck('id')
            ->merge($user->sitesAsAdmin()->pluck('sites.id'))
            ->merge($user->sitesAsEngineer()->pluck('sites.id'))
            ->merge($user->solicitanteSites()->pluck('sites.id'))
            ->unique()->values();
    }

    /**
     * Eventos ya levantados por el contacto (vía los checkpoints de sus hilos), con
     * un RESUMEN rico (estado, sistema, dispositivo, prioridad, quién atiende, último
     * avance registrado y datos capturados) para que el agente dé una buena respuesta
     * de estatus, no solo folio+estado.
     */
    private function contactEvents(?\App\Models\CaptureContact $contact): array
    {
        if (! $contact) return [];

        $convIds = $contact->conversations()->pluck('id');
        if ($convIds->isEmpty()) return [];

        $eventIds = \App\Models\CaptureMessage::whereIn('conversation_id', $convIds)
            ->where('direction', 'system')->get(['payload'])
            ->map(fn ($m) => is_array($m->payload) ? ($m->payload['event_id'] ?? null) : null)
            ->filter()->unique()->values();
        if ($eventIds->isEmpty()) return [];

        $events = \App\Models\Event::whereIn('id', $eventIds)
            ->with([
                'status:id,label', 'site:id,name', 'system:id,label', 'assignee:id,name',
                'device:id,name,brand,model', 'history',
            ])
            ->orderByDesc('created_at')->limit(8)->get();

        // Metadatos de los campos dinámicos por tipo de evento (etiqueta + tipo, para
        // nombrar y formatear los field_values).
        $fieldMeta = \App\Models\EventTypeField::whereIn('event_type_id', $events->pluck('event_type_id')->unique()->filter())
            ->where('is_active', true)->get(['event_type_id', 'field_key', 'label', 'field_type'])
            ->groupBy('event_type_id')
            ->map(fn ($g) => $g->keyBy('field_key')->map(fn ($f) => ['label' => $f->label, 'type' => $f->field_type]));

        $base = rtrim((string) config('app.frontend_url'), '/');

        return $events->map(function ($e) use ($fieldMeta, $base) {
            // Último avance = la última nota de un cambio de estado.
            $note = $e->history->filter(fn ($h) => trim((string) $h->note) !== '')->last();

            // Dispositivo asociado (nombre + marca/modelo si hay).
            $device = null;
            if ($e->device) {
                $device = $e->device->name;
                $extra = trim(((string) $e->device->brand) . ' ' . ((string) $e->device->model));
                if ($extra !== '') $device .= " ({$extra})";
            }

            // Datos capturados relevantes (field_values), nombrados con su etiqueta y
            // con los booleanos como Sí/No.
            $fields = [];
            $meta = $fieldMeta->get($e->event_type_id, collect());
            foreach ((array) $e->field_values as $key => $val) {
                if ($val === null || $val === '' || $val === []) continue;
                $m = $meta->get($key);
                $label = $m['label'] ?? $key;
                if (($m['type'] ?? null) === 'boolean') {
                    $value = in_array($val, [true, 1, '1', 'true', 'sí', 'si'], true) ? 'Sí' : 'No';
                } elseif (is_array($val)) {
                    $value = implode(', ', array_map(fn ($v) => (string) $v, $val));
                } else {
                    $value = (string) $val;
                }
                $fields[(string) $label] = $value;
                if (count($fields) >= 5) break;
            }

            return array_filter([
                'folio'         => $e->folio,
                'estado'        => optional($e->status)->label,
                'reporte'       => $e->description ? \Illuminate\Support\Str::limit((string) $e->description, 160) : null,
                'sitio'         => optional($e->site)->name,
                'sistema'       => optional($e->system)->label,
                'prioridad'     => $e->priority,
                'dispositivo'   => $device,
                'atiende'       => optional($e->assignee)->name,
                'reportado'     => optional($e->created_at)->format('Y-m-d'),
                'ultimo_avance' => $note ? \Illuminate\Support\Str::limit(trim((string) $note->note), 180) : null,
                'datos'         => ! empty($fields) ? $fields : null,
                'enlace'        => $base !== '' ? "{$base}/events/{$e->id}" : null,
            ], fn ($v) => $v !== null && $v !== '' && $v !== []);
        })->all();
    }

    // ── Conocimiento (RAG) para soporte de 1er nivel ─────────────────

    /**
     * Recupera fragmentos del manual del SISTEMA en curso (y del cliente, si aplica)
     * para dar soporte de 1er nivel. Solo cuando la línea tiene soporte activo, ya se
     * conoce el sistema, y hay un mensaje entrante que usar como consulta. Falla suave.
     *
     * @return array<int,array{heading:?string,content:string,score:float,audience:string}>
     */
    private function knowledgeFor(CaptureConversation $conversation, Channel $channel, ?\App\Models\CaptureContact $contact, $sites, $systems, string $supportMode): array
    {
        if ($supportMode === Channel::SUPPORT_OFF) {
            return [];
        }

        // Sistema en curso: el del estado del hilo, o el único posible del sitio.
        $systemId = $conversation->state['system_id'] ?? null;
        if (! $systemId && collect($systems)->count() === 1) {
            $systemId = collect($systems)->first()['id'];
        }
        $query = $this->lastInbound($conversation);
        if (! $systemId || $query === '') {
            return [];
        }

        $rag = app(RagService::class);
        if (! $rag->isOperational()) {
            return [];
        }

        try {
            return $rag->search($query, topK: 6, minScore: 0.12, filters: [
                'collection' => \App\Models\AiDocument::COLLECTION_SUPPORT,
                'catalog_id' => (int) $systemId,
                'client_id'  => $this->contextClientId($channel, $contact, $conversation),
            ]);
        } catch (\Throwable) {
            return [];
        }
    }

    /** Último mensaje entrante (síntoma) para usar como consulta de recuperación. */
    private function lastInbound(CaptureConversation $conversation): string
    {
        $m = $conversation->messages()->where('direction', 'in')
            ->reorder('created_at', 'desc')->first();
        return trim((string) ($m->body ?? ''));
    }

    /** Cliente del contexto (para artículos específicos por cliente); null si no se sabe. */
    private function contextClientId(Channel $channel, ?\App\Models\CaptureContact $contact, CaptureConversation $conversation): ?int
    {
        if ($contact && $contact->client_id) return (int) $contact->client_id;
        if ($channel->client_id) return (int) $channel->client_id;

        $siteId = $conversation->state['site_id'] ?? null;
        if ($siteId) {
            $clientId = Site::whereKey($siteId)->value('client_id');
            return $clientId ? (int) $clientId : null;
        }
        return null;
    }

    // ── Prompt + historial ───────────────────────────────────────────

    private function history(CaptureConversation $conversation): array
    {
        // Solo el intercambio conversacional: entrantes (user) y respuestas nuestras
        // —IA o humano— (assistant). Los checkpoints 'system' no van al modelo (se
        // reflejan en la memoria). Se toma la cola reciente; lo anterior vive en la memoria.
        return $conversation->messages()
            ->whereIn('direction', ['in', 'out', 'human'])
            ->reorder('created_at', 'desc')->limit(self::HISTORY_LIMIT)->get()
            ->sortBy('created_at')
            ->map(fn ($m) => [
                'role'    => $m->direction === 'in' ? 'user' : 'assistant',
                'content' => (string) $m->body,
            ])->filter(fn ($m) => $m['content'] !== '')->values()->all();
    }

    private function systemPrompt(Channel $channel, ?\App\Models\CaptureContact $contact, $sites, $systems, array $events, array $deviceCandidates = [], string $memory = '', array $knowledge = [], string $supportMode = 'off'): string
    {
        $agent = $channel->agent_name ?: 'Asistente';
        $extra = trim((string) $channel->instructions);

        // Alcance: el ETIQUETADO DEL CONTACTO manda (sitio fijo o cliente fijo);
        // si no, la línea (dedicada a un cliente o multi-cliente).
        if ($sites->count() === 1) {
            $s = $sites->first();
            $siteName   = $s['name'] ?? 'su sitio';
            $clientName = $s['client'] ?? 'su cliente';
            $scopePhrase = "de \"{$clientName}\"";
            $clientRule  = "- Ya SABES de dónde escribe esta persona: sitio \"{$siteName}\" (cliente \"{$clientName}\"). "
                . "NO preguntes el cliente NI el sitio; da por hecho ese sitio y solo identifica el SISTEMA y la DESCRIPCIÓN.";
        } elseif ($contact && $contact->client_id) {
            $clientName  = optional($contact->client)->name ?? 'su cliente';
            $scopePhrase = "de \"{$clientName}\"";
            $clientRule  = "- Esta persona pertenece al cliente \"{$clientName}\". NO preguntes el cliente; "
                . "solo identifica el SITIO (entre los de ese cliente) y el SISTEMA.";
        } elseif ($channel->client_id) {
            $clientName  = optional($channel->client)->name ?? 'el cliente';
            $scopePhrase = "de \"{$clientName}\"";
            $clientRule  = "- Esta línea atiende a un solo cliente ({$clientName}); no preguntes por el cliente.";
        } else {
            $scopePhrase = 'de mantenimiento (atiende a varios clientes)';
            $clientRule  = "- Esta línea atiende a VARIOS clientes. Cada sitio indica su cliente en el campo "
                . "\"client\"; el CLIENTE SE DEDUCE DEL SITIO que elija la persona. Si no queda claro a qué "
                . "sitio se refiere, pregúntalo ofreciendo las opciones (puedes agruparlas por cliente).";
        }

        $sitesJson   = json_encode($sites->values()->all(), JSON_UNESCAPED_UNICODE);
        $systemsJson = json_encode($systems->values()->all(), JSON_UNESCAPED_UNICODE);

        // Memoria acumulada del hilo (para no re-preguntar lo ya sabido).
        $memory = trim($memory);
        $memoryBlock = $memory !== ''
            ? "MEMORIA DEL CONTACTO (lo que ya sabes de conversaciones/eventos previos en este mismo hilo; úsala para NO volver a preguntar lo ya dicho):\n{$memory}\n"
            : '';

        // Dispositivos candidatos (cuando ya se ofrecieron opciones y falta que elija).
        $devicesBlock = ! empty($deviceCandidates)
            ? "DISPOSITIVOS CANDIDATOS (la persona debe elegir uno de estos; empátalos con lo que responda —por nombre, por número de la lista o por descripción como 'el de la línea 11'— y pon su id en \"device_id\". Si dice \"ninguno\"/\"no importa\", device_id=null). Elegir el dispositivo NO cambia que el reporte ya esté confirmado: si ya estaba listo, mantén ready=true:\n"
                . json_encode(array_values($deviceCandidates), JSON_UNESCAPED_UNICODE) . "\n"
            : '';

        // Eventos ya levantados por este contacto (para responder por su estatus).
        $eventsBlock = ! empty($events)
            ? "EVENTOS YA REPORTADOS POR ESTA PERSONA (datos para responder su estatus). Cada uno puede traer: "
                . "folio, estado, reporte (lo que reportó), sitio, sistema, prioridad, dispositivo, quién lo atiende, "
                . "fecha, ultimo_avance (última nota de seguimiento), datos capturados y enlace (para ver el detalle):\n"
                . json_encode(array_values($events), JSON_UNESCAPED_UNICODE) . "\n"
            : "ESTA PERSONA NO TIENE EVENTOS PREVIOS EN ESTE HILO. Si pregunta por el estatus de un folio que no aparece aquí, no inventes: dile que lo verificará uno de nuestros agentes de solución.\n";

        // Conocimiento del sistema (RAG) para soporte de 1er nivel. Se separa lo
        // ACCIONABLE (audience=support) de los CRITERIOS de comunicación (internal).
        $support = array_values(array_filter($knowledge, fn ($k) => ($k['audience'] ?? 'support') !== 'internal'));
        $internal = array_values(array_filter($knowledge, fn ($k) => ($k['audience'] ?? 'support') === 'internal'));

        $fmt = fn ($items) => implode("\n\n", array_map(function ($k) {
            $h = trim((string) ($k['heading'] ?? ''));
            return ($h !== '' ? "### {$h}\n" : '') . trim((string) $k['content']);
        }, $items));

        $knowledgeBlock = '';
        if ($support !== []) {
            $knowledgeBlock .= "CONOCIMIENTO DEL SISTEMA (extractos del manual del sistema en cuestión; ÚSALO para dar soporte "
                . "de 1er nivel guiando al cliente con estos pasos. NO inventes procedimientos que no estén aquí; si el "
                . "conocimiento no cubre el caso, no fuerces una solución):\n" . $fmt($support) . "\n\n";
        }
        if ($internal !== []) {
            $knowledgeBlock .= "CRITERIOS DE RESPUESTA (cómo comunicar con el cliente; guíate por esto, NO lo cites textual):\n"
                . $fmt($internal) . "\n\n";
        }

        // Regla de comportamiento del soporte según el modo de la línea.
        $supportRule = '';
        if ($supportMode === Channel::SUPPORT_DEFLECT && $knowledgeBlock !== '') {
            $supportRule = "- SOPORTE DE 1er NIVEL (resolver primero): cuando el cliente reporte una falla y el CONOCIMIENTO "
                . "tenga una solución aplicable, guíalo por los pasos (uno o dos a la vez, en lenguaje simple) ANTES de "
                . "levantar el reporte. Si el cliente confirma que se resolvió, NO crees el evento: ciérralo con cordialidad "
                . "(is_ticket=false, ready=false, resolved=true). Si los pasos no funcionan, el cliente prefiere que lo "
                . "atienda un agente, o el conocimiento no aplica, procede a levantar el reporte como siempre (resolved=false).";
        } elseif ($supportMode === Channel::SUPPORT_ASSIST && $knowledgeBlock !== '') {
            $supportRule = "- SOPORTE DE 1er NIVEL (acompañar): además de reunir los datos y levantar el reporte, si el "
                . "CONOCIMIENTO tiene pasos aplicables, compártelos de forma breve para que el cliente pueda intentar "
                . "resolverlo mientras un agente lo atiende. Igual procede a registrar el reporte.";
        }

        $rules = <<<PROMPT
        Eres "{$agent}", el asistente de captación de reportes {$scopePhrase} (sistemas como
        CCTV, detección de incendio, control de acceso, etc.).

        Tu trabajo: por WhatsApp/Telegram, identificar si la persona quiere REPORTAR UNA FALLA
        o SOLICITAR UN SERVICIO y reunir los datos para levantar el evento. Si es solo un saludo
        o charla, responde con amabilidad y ofrece ayuda, sin crear nada.

        Este es un HILO PERSISTENTE con la misma persona: puede haber levantado reportes antes y
        volver a escribir. NO se reinicia entre reportes. Aprovecha lo que ya sabes (ver MEMORIA)
        para no pedir de nuevo el sitio/cliente si ya quedó claro.

        {$memoryBlock}
        {$eventsBlock}
        {$devicesBlock}
        {$knowledgeBlock}
        Datos que necesitas para levantar el evento (obligatorios): SITIO, SISTEMA y DESCRIPCIÓN.
        La prioridad es opcional (baja/media/alta/critica); si la persona transmite urgencia, súbela.

        Reglas:
        {$clientRule}
        {$supportRule}
        - SEGUIMIENTO / ESTATUS: si la persona pregunta cómo va su reporte, en qué va, o por un folio, RESUME de
          forma natural y cordial la información de "EVENTOS YA REPORTADOS". Menciona SIEMPRE el folio y el estado, e
          incluye TODO dato presente que sea útil: qué reportó, el sistema, el DISPOSITIVO (si el evento trae
          "dispositivo", nómbralo explícitamente), la prioridad, quién lo atiende y el "ultimo_avance". Redáctalo como
          un mensaje breve y claro para la persona, NO como una lista de campos crudos ni JSON. Si el evento trae
          "enlace", ciérralo ofreciéndolo, p. ej.: "Si quieres ver más detalles, lo encuentras aquí: <enlace>". Si
          tiene varios eventos, resume el más reciente (o el que mencione por folio) y ofrece detallar otro. Es una
          CONSULTA: is_ticket=false y ready=false (no crees ni modifiques nada). Si pide algo que no puedes resolver
          con esos datos, ofrécele que uno de nuestros agentes de solución lo revisará.
        - Resuelve el SITIO y el SISTEMA contra estas listas (usa sus IDs exactos):
          SITIOS (cada uno con su cliente y los sistemas disponibles): {$sitesJson}
          SISTEMAS: {$systemsJson}
        - El sistema elegido debe pertenecer al sitio elegido (según system_ids del sitio).
        - Si solo hay un sitio posible, úsalo sin preguntar. Si hay varios y no queda claro, pregúntalo.
        - Si la MEMORIA ya indica el sitio/cliente habitual de la persona y no dice otro, úsalo sin volver a preguntar.
        - Si falta algún dato obligatorio, pide SOLO lo que falta, en una pregunta corta y clara.
        - CALIDAD DE LA DESCRIPCIÓN: la descripción debe explicar QUÉ está pasando (el síntoma o la
          solicitud), no solo un identificador ni frases vacías como "sin reportar" o "tiene un problema".
          Si es demasiado vaga, pide UNA aclaración breve y concreta con ejemplos (p. ej. "¿Qué presenta?
          ¿No enciende, marca falla, no comunica, no graba?"). Es un ACOMPAÑAMIENTO, no un bloqueo: si tras
          pedirla la persona no da más detalle, procede igual con lo que haya. No insistas más de una vez.
        - DISPOSITIVO: si la persona menciona un equipo puntual (una serie/clave como "DH99A78", un nombre
          o una ubicación de dispositivo), ponlo en "device_hint" tal cual lo dijo, para intentar ligar el
          evento a ese dispositivo. No inventes claves; si no menciona ninguno, device_hint = null.
        - NUNCA inventes sitios, sistemas ni datos. Si no puedes mapear lo que dice a la lista,
          ofrece las opciones disponibles.
        - Cuando ya tengas sitio + sistema + descripción, RESUME lo entendido (incluye el sitio y
          su cliente) y pide confirmación ("¿Es correcto? Responde sí para registrarlo"). Marca
          ready=true SOLO cuando la persona confirme.
        - Tras registrar un evento, si la persona AGREGA más detalles del MISMO reporte, agradécelo
          y dile que queda anotado para el técnico asignado (no necesitas crear otro evento). Si es
          una falla DISTINTA, trátala como un reporte nuevo desde cero.
        - Nunca digas que "no puedes" por razones técnicas ni menciones "pasos", herramientas ni
          límites internos. NUNCA hables de "un humano": si algo excede lo que registras aquí, di con
          naturalidad que lo verá o atenderá "uno de nuestros agentes de solución" (o "un agente
          especializado").
        - Responde SIEMPRE en español, breve y cordial.
        {$extra}

        Devuelve EXCLUSIVAMENTE un objeto JSON válido (sin texto alrededor, sin ```), con esta forma:
        {
          "is_ticket": true|false,          // ¿es una solicitud/reporte de servicio?
          "site_id": number|null,           // ID de la lista de SITIOS, o null
          "system_id": number|null,         // ID de la lista de SISTEMAS (válido para el sitio), o null
          "description": string|null,       // qué falla/solicita (el SÍNTOMA), en una o dos frases
          "device_hint": string|null,       // equipo/serie/clave/ubicación mencionada, o null
          "device_id": number|null,         // SOLO si hay DISPOSITIVOS CANDIDATOS y la persona eligió uno
          "priority": "baja"|"media"|"alta"|"critica"|null,
          "ready": true|false,              // true SOLO tras la confirmación explícita del usuario
          "resolved": true|false,           // SOLO en soporte "resolver primero": el cliente confirmó que se solucionó con la guía (no crear evento)
          "reply": string,                  // el mensaje que se le enviará a la persona ahora
          "memory": string                  // MEMORIA actualizada y BREVE del contacto (sitio/cliente habitual,
                                            // datos útiles, pendientes). Reescríbela completa cada vez, máx ~600 caracteres.
        }
        PROMPT;

        return $rules;
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function parseJson(string $content): ?array
    {
        $content = trim($content);
        $start = strpos($content, '{');
        $end   = strrpos($content, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $json = substr($content, $start, $end - $start + 1);
        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    private function validId($value, $allowed): ?int
    {
        $id = is_numeric($value) ? (int) $value : null;
        return $id && $allowed->contains($id) ? $id : null;
    }

    private function validPriority($value): ?string
    {
        $v = is_string($value) ? strtolower(trim($value)) : '';
        return in_array($v, ['baja', 'media', 'alta', 'critica'], true) ? $v : null;
    }

    private function fallback(string $reply, array $usage = ['input' => 0, 'output' => 0]): array
    {
        return [
            'ok' => true, 'is_ticket' => false, 'site_id' => null, 'system_id' => null,
            'description' => null, 'priority' => null, 'device_hint' => null, 'device_id' => null,
            'ready' => false, 'reply' => $reply, 'memory' => null, 'usage' => $usage,
        ];
    }
}
