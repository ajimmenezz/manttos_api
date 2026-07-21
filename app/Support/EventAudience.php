<?php

namespace App\Support;

use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Quién "le importa" un evento: admins globales + admins del cliente + admins del sitio
 * + ingenieros del cliente + ingenieros del sitio (todos activos).
 *
 * Es el conjunto de destinatarios para las notificaciones de evento y, a la vez, el
 * universo de usuarios arrobables en los comentarios. Antes esta consulta vivía dentro
 * de EventCommentController; se centraliza aquí para no duplicarla al notificar por
 * creación / cambio de estado / etc.
 */
class EventAudience
{
    /**
     * IDs de usuario interesados en el evento.
     *
     * @return array<int,int>
     */
    public static function interested(Event $event): array
    {
        return static::interestedUsers($event)->pluck('id')->all();
    }

    /**
     * Usuarios interesados (modelos con id, name, email), ordenados por nombre.
     * Sirve para el selector de @menciones, que necesita mostrar nombre/correo.
     *
     * @return EloquentCollection<int,User>
     */
    public static function interestedUsers(Event $event): EloquentCollection
    {
        // Solo roles que EXISTAN y NO estén archivados: el scope role() de Spatie usa
        // App\Models\Role (con SoftDeletes) y lanza RoleDoesNotExist si el rol no está.
        $adminRoles = DB::table('roles')
            ->whereIn('name', ['superadmin', 'admin'])
            ->where('guard_name', 'web')
            ->whereNull('deleted_at')
            ->pluck('name')->all();

        $ids = collect()
            ->merge($adminRoles ? User::role($adminRoles)->pluck('id') : [])
            ->merge(DB::table('client_user')->where('client_id', $event->client_id)->pluck('user_id'))
            ->merge(DB::table('site_user')->where('site_id', $event->site_id)->pluck('user_id'))
            ->merge(DB::table('client_engineers')->where('client_id', $event->client_id)->pluck('user_id'))
            ->merge(DB::table('site_engineers')->where('site_id', $event->site_id)->pluck('user_id'))
            ->unique()->values();

        return User::whereIn('id', $ids)->where('is_active', true)
            ->orderBy('name')->get(['id', 'name', 'email']);
    }
}
