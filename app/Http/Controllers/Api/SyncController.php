<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Event;
use App\Models\EventStatus;
use App\Support\EventSla;
use App\Models\Maintenance;
use App\Models\MaintenanceActivity;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * /v1/sync/* — lectura para SINCRONIZAR otro sistema (el tablero de sistemas especiales de Siccob), no para pantallas.
 *
 * Por qué un grupo aparte y no los endpoints de /v1 que ya existen:
 * - /v1 está pensado para un front: un mantenimiento a la vez, paginado por página. Para copiar los datos hace
 *   falta lo contrario: todo lo del alcance, plano, y «solo lo que cambió desde X».
 * - /v1 EXCLUYE dispositivos a propósito: su controlador no autoriza por usuario (fuga entre clientes). Aquí cada
 *   consulta se recorta a `siteIds()` del usuario de la llave, así que exponerlos es seguro.
 *
 * Contrato común: `updated_since` (ISO 8601) trae solo lo cambiado desde esa fecha; sin él, todo (para que el otro
 * lado concilie bajas). Paginación por cursor (`cursor` + `next_cursor`), `per_page` hasta 500. Fechas en ISO 8601.
 */
class SyncController extends Controller
{
    private const POR_PAGINA = 200;
    private const TOPE = 500;

    /** Sitios que puede ver el usuario de la llave. superadmin/admin no pueden emitir llaves, pero se cubren igual. */
    private function siteIds(User $user): ?Collection
    {
        if ($user->hasAnyRole(['superadmin', 'admin'])) {
            return null; // sin recorte
        }
        $clientes = collect();
        if ($user->hasRole('admin-cliente')) {
            $clientes = $clientes->merge($user->clientsAsAdmin()->pluck('clients.id'));
        }
        if ($user->hasRole('ingeniero')) {
            $clientes = $clientes->merge($user->clientsAsEngineer()->pluck('clients.id'));
        }
        $ids = Site::whereIn('client_id', $clientes->unique())->pluck('id');
        if ($user->hasRole('admin-sitio')) {
            $ids = $ids->merge($user->sitesAsAdmin()->pluck('sites.id'));
        }
        if ($user->hasRole('ingeniero')) {
            $ids = $ids->merge($user->sitesAsEngineer()->pluck('sites.id'));
        }
        return $ids->unique()->values();
    }

    private function recortar(Request $request, Builder $query, string $columnaSitio): Builder
    {
        $ids = $this->siteIds($request->user());
        if ($ids !== null) {
            $query->whereIn($columnaSitio, $ids);
        }
        if ($request->filled('site_id')) {
            $query->where($columnaSitio, (int) $request->site_id);
        }
        return $query;
    }

    /** `updated_since` + orden estable para el cursor (updated_at, id). */
    private function pagina(Request $request, Builder $query, string $tabla, callable $fila): JsonResponse
    {
        $request->validate([
            'updated_since' => ['nullable', 'date'],
            'per_page'      => ['nullable', 'integer', 'min:1', 'max:' . self::TOPE],
            'site_id'       => ['nullable', 'integer'],
        ]);
        if ($request->filled('updated_since')) {
            $query->where("$tabla.updated_at", '>=', $request->date('updated_since'));
        }
        $pagina = $query->orderBy("$tabla.updated_at")->orderBy("$tabla.id")
            ->cursorPaginate((int) ($request->per_page ?? self::POR_PAGINA));

        return response()->json([
            'data'        => collect($pagina->items())->map($fila)->values(),
            'next_cursor' => $pagina->nextCursor()?->encode(),
            'server_time' => now()->toIso8601String(), // el siguiente updated_since; con el reloj del servidor, no el del cliente
        ]);
    }

    private static function fecha($valor): ?string
    {
        return $valor ? \Illuminate\Support\Carbon::parse($valor)->toIso8601String() : null;
    }

    public function sites(Request $request): JsonResponse
    {
        $query = $this->recortar($request, Site::query()->with('client:id,name,short_name'), 'sites.id');

        return $this->pagina($request, $query, 'sites', fn (Site $s) => [
            'id'          => $s->id,
            'client_id'   => $s->client_id,
            'client_name' => $s->client?->name,
            'name'        => $s->name,
            'code'        => $s->code,
            'type'        => $s->type,
            'address'     => $s->address,
            'city'        => $s->city,
            'state'       => $s->state,
            'country'     => $s->country,
            'is_active'   => (bool) $s->is_active,
            'updated_at'  => self::fecha($s->updated_at),
        ]);
    }

    public function devices(Request $request): JsonResponse
    {
        $query = Device::query()
            ->join('directories', 'directories.id', '=', 'devices.directory_id')
            ->leftJoin('catalogs as sistema', 'sistema.id', '=', 'directories.catalog_id')
            ->select('devices.*', 'directories.site_id', 'directories.catalog_id as system_id', 'sistema.label as system_label');
        $this->recortar($request, $query, 'directories.site_id');

        return $this->pagina($request, $query, 'devices', fn (Device $d) => [
            'id'            => $d->id,
            'site_id'       => $d->site_id,
            'directory_id'  => $d->directory_id,
            'system_id'     => $d->system_id,
            'system'        => $d->system_label,
            'name'          => $d->name,
            'device_type'   => $d->device_type,
            'brand'         => $d->brand,
            'model'         => $d->model,
            'serial_number' => $d->serial_number,
            'location'      => $d->location,
            'status'        => $d->status,
            'is_active'     => (bool) $d->is_active,
            'archived_at'   => self::fecha($d->archived_at),
            'custom_fields' => $d->custom_fields ?? (object) [],
            'updated_at'    => self::fecha($d->updated_at),
        ]);
    }

    public function maintenances(Request $request): JsonResponse
    {
        $query = $this->recortar($request, Maintenance::query()->with('system:id,label'), 'maintenances.site_id');

        return $this->pagina($request, $query, 'maintenances', fn (Maintenance $m) => [
            'id'          => $m->id,
            'site_id'     => $m->site_id,
            'system_id'   => $m->catalog_id,
            'system'      => $m->system?->label,
            'type'        => $m->type,
            'status'      => $m->status,
            'start_date'  => self::fecha($m->start_date),
            'end_date'    => self::fecha($m->end_date),
            'archived_at' => self::fecha($m->archived_at),
            'updated_at'  => self::fecha($m->updated_at),
        ]);
    }

    public function activities(Request $request): JsonResponse
    {
        $query = MaintenanceActivity::query()
            ->join('maintenances', 'maintenances.id', '=', 'maintenance_activities.maintenance_id')
            ->leftJoin('catalogs as tipo', 'tipo.id', '=', 'maintenance_activities.activity_type_id')
            ->leftJoin('users', 'users.id', '=', 'maintenance_activities.user_id')
            ->select('maintenance_activities.*', 'maintenances.site_id', 'tipo.label as activity_type', 'users.name as user_name');
        $this->recortar($request, $query, 'maintenances.site_id');

        return $this->pagina($request, $query, 'maintenance_activities', fn (MaintenanceActivity $a) => [
            'id'               => $a->id,
            'maintenance_id'   => $a->maintenance_id,
            'site_id'          => $a->site_id,
            'device_id'        => $a->device_id,
            'activity_type_id' => $a->activity_type_id,
            'activity_type'    => $a->activity_type,
            'user_name'        => $a->user_name,
            'performed_at'     => self::fecha($a->performed_at),
            'field_values'     => $a->field_values ?? (object) [],
            'updated_at'       => self::fecha($a->updated_at),
        ]);
    }

    public function events(Request $request): JsonResponse
    {
        $terminales = EventStatus::where('is_terminal', true)->pluck('id');
        // SLA con la MISMA medición que la ficha del evento (EventSla::measure): plazos por nivel en horario hábil.
        // El consumidor recibe plazo y hora de atención por nivel, así puede decidir «vencido» con su propio reloj
        // aunque el evento no haya cambiado (un pendiente se vuelve vencido sin tocar updated_at).
        $tiers     = EventSla::tiers();
        $statusMap = EventStatus::whereNotNull('sla_tier_id')->pluck('sla_tier_id', 'id')->all();
        $ajustes   = [];
        $query = Event::query()
            ->with(['system:id,label', 'eventType:id,label,nature', 'status:id,key,label,category,is_terminal', 'assignee:id,name', 'history'])
            // Cuándo quedó cerrado: la última vez que pasó a un estado terminal (si hoy está en uno).
            ->addSelect(['events.*', 'closed_at' => \App\Models\EventStatusHistory::query()
                ->selectRaw('max(created_at)')->whereColumn('event_id', 'events.id')
                ->whereIn('to_status_id', $terminales)]);
        $this->recortar($request, $query, 'events.site_id');

        return $this->pagina($request, $query, 'events', function (Event $e) use (&$ajustes, $statusMap, $tiers) { return [
            'sla'                    => EventSla::measure($e, $ajustes[$e->client_id] ??= EventSla::resolve($e->client_id), $statusMap, $tiers),
            // Primera respuesta = primer cambio de estado REAL (con estado anterior): el historial también registra
            // el estado inicial al crear el evento, y contarlo daría «respondido en 0 h» en todos.
            'first_response_at'      => self::fecha($e->history->first(fn ($h) => $h->from_status_id !== null)?->created_at),
            'id'                     => $e->id,
            'folio'                  => $e->folio,
            'client_id'              => $e->client_id,
            'site_id'                => $e->site_id,
            'system_id'              => $e->system_id,
            'system'                 => $e->system?->label,
            'event_type'             => $e->eventType?->label,
            'nature'                 => $e->eventType?->nature,
            'device_id'              => $e->device_id,
            'status_key'             => $e->status?->key,
            'status'                 => $e->status?->label,
            'status_category'        => $e->status?->category,
            'is_closed'              => (bool) $e->status?->is_terminal,
            'priority'               => $e->priority,
            'impact'                 => $e->impact,
            'urgency'                => $e->urgency,
            'description'            => $e->description,
            'assigned_to'            => $e->assignee?->name,
            'assigned_at'            => self::fecha($e->assigned_at),
            'scheduled_attention_at' => self::fecha($e->scheduled_attention_at),
            'occurred_at'            => self::fecha($e->occurred_at),
            'created_at'             => self::fecha($e->created_at),
            'closed_at'              => $e->status?->is_terminal ? self::fecha($e->closed_at) : null,
            'archived_at'            => self::fecha($e->archived_at),
            'updated_at'             => self::fecha($e->updated_at),
        ]; });
    }
}
