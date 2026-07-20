<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Alcance del chat interno (sección 4.4 del spec) — CRÍTICO por ser multi-cliente.
 *
 * Reglas, en una sola frase: dos usuarios pueden conversar si alguno es administrador
 * GLOBAL, o si ambos son personal INTERNO, o si comparten al menos un CLIENTE.
 * De ahí se deriva todo lo demás:
 *  - un solicitante/usuario de cliente jamás ve ni escribe a usuarios de otro cliente;
 *  - un solicitante sí alcanza al personal interno ASIGNADO a sus clientes/sitios
 *    (porque ese personal comparte cliente con él) y a los administradores globales;
 *  - un ingeniero alcanza a todo el personal interno y a los usuarios de los clientes
 *    que atiende.
 *
 * El "cliente" de un usuario se deriva de los pivotes que ya existen en el sistema:
 * client_user / site_user (administradores), client_engineers / site_engineers
 * (personal asignado) y solicitante_client / solicitante_site (portal).
 */
class ChatScope
{
    /** Roles que ven y escriben a cualquiera (soporte / dirección). */
    public const GLOBAL_ROLES = ['superadmin', 'admin'];

    /** Roles que marcan a un usuario como "de cliente" (externo), nunca interno. */
    public const EXTERNAL_ROLES = ['solicitante', 'admin-cliente', 'admin-sitio'];

    /** ¿Alcance global? (superadmin/admin o permiso explícito de auditoría). */
    public static function isGlobal(User $user): bool
    {
        return $user->hasAnyRole(self::GLOBAL_ROLES) || $user->can('chat.all-conversations');
    }

    /**
     * Personal interno = no tiene rol de cliente ni asignaciones de solicitante.
     * Se define por exclusión a propósito: si mañana nace un rol nuevo de cliente,
     * basta agregarlo a EXTERNAL_ROLES o darle sus pivotes de solicitante.
     */
    public static function isInternal(User $user): bool
    {
        if ($user->hasAnyRole(self::EXTERNAL_ROLES)) {
            return false;
        }
        return ! $user->solicitanteClients()->exists() && ! $user->solicitanteSites()->exists();
    }

    /** Clientes a los que pertenece / atiende un usuario (unión de todos los pivotes). */
    public static function clientIds(User $user): Collection
    {
        return collect()
            ->merge($user->clientsAsAdmin()->pluck('clients.id'))
            ->merge($user->sitesAsAdmin()->pluck('sites.client_id'))
            ->merge($user->clientsAsEngineer()->pluck('clients.id'))
            ->merge($user->sitesAsEngineer()->pluck('sites.client_id'))
            ->merge($user->solicitanteClients()->pluck('clients.id'))
            ->merge($user->solicitanteSites()->pluck('sites.client_id'))
            ->filter()
            ->unique()
            ->values();
    }

    /** ¿Puede $a conversar con $b? Simétrica por construcción. */
    public static function canContact(User $a, User $b): bool
    {
        if ($a->id === $b->id) {
            return false;
        }
        if (self::isGlobal($a) || self::isGlobal($b)) {
            return true;
        }
        if (self::isInternal($a) && self::isInternal($b)) {
            return true;
        }
        return self::clientIds($a)->intersect(self::clientIds($b))->isNotEmpty();
    }

    /**
     * Directorio de usuarios contactables por $user, ya filtrado por alcance.
     * Devuelve un query de User (activo, distinto de sí mismo) para paginar/buscar.
     */
    public static function contactableQuery(User $user)
    {
        $query = User::query()
            ->where('users.id', '!=', $user->id)
            ->where('users.is_active', true);

        if (self::isGlobal($user)) {
            return $query;
        }

        $clientIds = self::clientIds($user);
        $internal  = self::isInternal($user);

        return $query->where(function ($w) use ($clientIds, $internal) {
            // Siempre alcanzables: los administradores globales (soporte).
            $w->whereHas('roles', fn ($r) => $r->whereIn('name', self::GLOBAL_ROLES));

            // Si yo soy interno, alcanzo a todo el personal interno.
            if ($internal) {
                $w->orWhere(fn ($x) => self::internalCondition($x));
            }

            // Y a quien comparta cliente conmigo (interno asignado o usuario del cliente).
            if ($clientIds->isNotEmpty()) {
                $w->orWhere(fn ($x) => self::sharesClientCondition($x, $clientIds));
            }
        });
    }

    /** Condición SQL: el usuario es personal interno (sin rol ni pivotes de cliente). */
    private static function internalCondition($query)
    {
        return $query
            ->whereDoesntHave('roles', fn ($r) => $r->whereIn('name', self::EXTERNAL_ROLES))
            ->whereDoesntHave('solicitanteClients')
            ->whereDoesntHave('solicitanteSites');
    }

    /** Condición SQL: el usuario está ligado a alguno de $clientIds por cualquier pivote. */
    private static function sharesClientCondition($query, Collection $clientIds)
    {
        return $query->where(function ($o) use ($clientIds) {
            $o->whereHas('clientsAsAdmin',     fn ($c) => $c->whereIn('clients.id', $clientIds))
              ->orWhereHas('sitesAsAdmin',     fn ($s) => $s->whereIn('sites.client_id', $clientIds))
              ->orWhereHas('clientsAsEngineer', fn ($c) => $c->whereIn('clients.id', $clientIds))
              ->orWhereHas('sitesAsEngineer',   fn ($s) => $s->whereIn('sites.client_id', $clientIds))
              ->orWhereHas('solicitanteClients', fn ($c) => $c->whereIn('clients.id', $clientIds))
              ->orWhereHas('solicitanteSites',   fn ($s) => $s->whereIn('sites.client_id', $clientIds));
        });
    }

    /**
     * Cliente que fija el alcance de una conversación: null si todos los participantes
     * son internos; si hay usuarios de cliente, el cliente que TODOS ellos comparten.
     * Devuelve false si los externos no comparten ninguno (mezcla prohibida).
     *
     * @param  \Illuminate\Support\Collection<int,User>  $users
     * @return int|null|false
     */
    public static function resolveConversationClient(Collection $users)
    {
        $externals = $users->reject(fn (User $u) => self::isInternal($u));
        if ($externals->isEmpty()) {
            return null;
        }

        $common = null;
        foreach ($externals as $u) {
            $ids = self::clientIds($u);
            $common = $common === null ? $ids : $common->intersect($ids);
        }

        return $common->isEmpty() ? false : (int) $common->first();
    }
}
