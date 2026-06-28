<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Site;
use Illuminate\Http\Request;

/**
 * Scope de visibilidad de eventos por rol, compartido entre el controlador de
 * operación (EventController) y el de reportería (EventDashboardController).
 */
trait ScopesEvents
{
    protected function scopeEvents(Request $request, $query)
    {
        $user = $request->user();

        if ($user->hasAnyRole(['superadmin', 'admin'])) {
            return $query;
        }
        if ($user->hasRole('admin-cliente')) {
            return $query->whereIn('events.client_id', $user->clientsAsAdmin()->pluck('clients.id'));
        }
        if ($user->hasRole('admin-sitio')) {
            return $query->whereIn('events.site_id', $user->sitesAsAdmin()->pluck('sites.id'));
        }
        if ($user->hasRole('ingeniero')) {
            // Asignación explícita: sitios de sus clientes asignados ∪ sitios asignados directos,
            // más los eventos que él mismo creó o tiene asignados.
            $siteIds = $this->engineerSiteIds($user);
            return $query->where(function ($q) use ($siteIds, $user) {
                $q->whereIn('events.site_id', $siteIds)
                    ->orWhere('events.created_by', $user->id)
                    ->orWhere('events.assigned_to', $user->id);
            });
        }
        return $query->whereRaw('1 = 0');
    }

    /** Sitios que un ingeniero puede atender: clientes asignados (→ todos sus sitios) ∪ sitios asignados. */
    protected function engineerSiteIds($user)
    {
        $clientIds = $user->clientsAsEngineer()->pluck('clients.id');
        $fromClients = Site::whereIn('client_id', $clientIds)->pluck('id');
        $direct = $user->sitesAsEngineer()->pluck('sites.id');
        return $fromClients->merge($direct)->unique()->values();
    }
}
