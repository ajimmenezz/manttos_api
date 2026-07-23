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
    protected function scopeEvents(Request $request, $query, bool $includeArchived = false)
    {
        $user = $request->user();

        // Eventos ARCHIVADOS fuera de la interfaz Y de la reportería por defecto (este es
        // el punto único de ambos). El manejo de archivados los incluye explícitamente.
        if (! $includeArchived) {
            $query->whereNull('events.archived_at');
        }

        // Ocultar eventos de clientes o sitios archivados (soft-deleted) para TODOS
        // los roles — incluida la reportería. whereHas respeta el SoftDeletes scope.
        $query->whereHas('client')->whereHas('site');

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
        if ($user->hasRole('solicitante')) {
            // Autoservicio: solo ve SUS propios reportes, aunque esté asociado a un cliente/sitio.
            return $query->where('events.created_by', $user->id);
        }

        // Rol no reconocido por nombre (p. ej. un rol nuevo "Solicitante" con events.create):
        // si tiene asignaciones como administrador de cliente/sitio, dale ese alcance; si no,
        // al menos lo que él mismo levantó. Así un rol nuevo con el permiso funciona sin tocar
        // este trait cada vez, siempre que se le asignen sus clientes/sitios.
        $clientIds = $user->clientsAsAdmin()->pluck('clients.id');
        $siteIds   = $user->sitesAsAdmin()->pluck('sites.id');
        if ($clientIds->isNotEmpty() || $siteIds->isNotEmpty()) {
            return $query->where(function ($q) use ($clientIds, $siteIds, $user) {
                $q->whereIn('events.client_id', $clientIds)
                    ->orWhereIn('events.site_id', $siteIds)
                    ->orWhere('events.created_by', $user->id);
            });
        }
        return $query->where('events.created_by', $user->id);
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
