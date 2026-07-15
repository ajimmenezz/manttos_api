<?php

namespace App\Services\Capture;

use App\Http\Controllers\Api\EventController;
use App\Models\Channel;
use App\Models\Device;
use App\Models\EventType;
use App\Models\User;
use App\Services\Ai\Support\ControllerInvoker;

/**
 * Crea el evento captado por el camino NORMAL (EventController::store vía
 * ControllerInvoker), atribuido al usuario configurado en la línea. Así hereda
 * folio por cliente, prioridad, validaciones e idempotencia, y cae al pool
 * (pendiente_captura) listo para asignarse.
 */
class EventCreator
{
    /**
     * @return array{ok:bool, folio?:string, event_id?:int, error?:string}
     */
    public function create(Channel $channel, int $siteId, int $systemId, string $description, ?string $priority, ?string $clientUuid = null, ?User $requester = null, ?int $deviceId = null): array
    {
        $user = $this->attributionUser($channel, $requester);
        if (! $user) {
            return ['ok' => false, 'error' => 'La línea no tiene un usuario válido para atribuir el evento.'];
        }

        $eventTypeId = $this->resolveEventType($channel, $systemId);
        if (! $eventTypeId) {
            return ['ok' => false, 'error' => 'No hay un tipo de evento configurado para ese sistema.'];
        }

        $payload = array_filter([
            'site_id'       => $siteId,
            'system_id'     => $systemId,
            'event_type_id' => $eventTypeId,
            'description'   => $description,
            'priority'      => $priority,
            'device_id'     => $deviceId,
            'client_uuid'   => $clientUuid,
        ], fn ($v) => $v !== null);

        $res = ControllerInvoker::post(EventController::class, 'store', $user, $payload);

        if (! ($res['ok'] ?? false)) {
            return ['ok' => false, 'error' => $res['error'] ?? 'No se pudo crear el evento.'];
        }

        $data = $res['data'] ?? [];
        return [
            'ok'       => true,
            'folio'    => $data['folio'] ?? ($data['event']['folio'] ?? null),
            'event_id' => $data['event']['id'] ?? null,
        ];
    }

    /**
     * Usuario bajo el que se crea el evento. Prioridad: (1) el SOLICITANTE reconocido
     * (usuario registrado que escribió) → el evento se atribuye a quien realmente lo
     * reportó y respeta su alcance; (2) líneas multi-cliente → superadmin (alcance
     * global); (3) líneas dedicadas → el usuario configurado y, si no, un superadmin.
     */
    private function attributionUser(Channel $channel, ?User $requester = null): ?User
    {
        if ($requester && $requester->is_active) {
            return $requester;
        }

        $superadmin = fn () => User::where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('name', 'superadmin'))->first();

        if (! $channel->client_id) {
            return $superadmin();
        }

        if ($channel->created_by_user_id) {
            $u = User::where('id', $channel->created_by_user_id)->where('is_active', true)->first();
            if ($u) return $u;
        }

        return $superadmin();
    }

    /**
     * Dispositivos del sitio que se parecen a lo que mencionó la persona (serie, nombre o
     * modelo), IGNORANDO separadores: "DH99A78" casa con "DH-99A-78". Prioriza los del
     * sistema reportado. Devuelve una lista corta [{id,name}] para que el agente confirme
     * o el usuario elija; vacía si no hay coincidencias razonables.
     *
     * @return array<int,array{id:int,name:string}>
     */
    public function deviceCandidates(int $siteId, int $systemId, ?string $hint, int $limit = 8): array
    {
        $norm = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $hint));
        if (mb_strlen($norm) < 3) {
            return [];
        }

        $base = fn (bool $systemOnly) => Device::where('is_active', true)
            ->whereHas('directory', fn ($d) => $d->where('site_id', $siteId)
                ->when($systemOnly, fn ($q) => $q->where('catalog_id', $systemId)))
            ->where(function ($w) use ($norm) {
                foreach (['serial_number', 'name', 'model'] as $col) {
                    $w->orWhereRaw("regexp_replace(upper(coalesce({$col}, '')), '[^A-Z0-9]', '', 'g') LIKE ?", ["%{$norm}%"]);
                }
            })
            ->orderBy('name')->limit($limit)->get(['id', 'name']);

        // Prefiere los del sistema reportado; si no hay, cualquiera del sitio.
        $rows = $base(true);
        if ($rows->isEmpty()) {
            $rows = $base(false);
        }

        return $rows->map(fn ($d) => ['id' => (int) $d->id, 'name' => (string) $d->name])->all();
    }

    /** Tipo de evento: el default de la línea, si no el primero activo ligado al sistema. */
    private function resolveEventType(Channel $channel, int $systemId): ?int
    {
        if ($channel->default_event_type_id) {
            return (int) $channel->default_event_type_id;
        }

        $linked = EventType::where('is_active', true)
            ->whereHas('linkedSystems', fn ($q) => $q->where('catalogs.id', $systemId))
            ->orderBy('sort_order')->value('id');

        return $linked ?: EventType::where('is_active', true)->orderBy('sort_order')->value('id');
    }
}
