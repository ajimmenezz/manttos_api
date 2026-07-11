<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteEngineerController extends Controller
{
    /** admin-cliente gestiona los sitios de sus clientes; admin-sitio sólo sus sitios. */
    private function authorizeSite(Request $request, Site $site): void
    {
        $user = $request->user();
        if ($user->hasAnyRole(['superadmin', 'admin'])) return;
        if ($user->hasRole('admin-cliente') && $user->clientsAsAdmin()->where('clients.id', $site->client_id)->exists()) return;
        if ($user->hasRole('admin-sitio') && $site->admins()->where('users.id', $user->id)->exists()) return;
        abort(403, 'No tienes acceso a este sitio.');
    }

    public function index(Request $request, Client $client, Site $site): JsonResponse
    {
        abort_unless($site->client_id === $client->id, 404);
        $this->authorizeSite($request, $site);
        abort_unless($request->user()->can('site-engineers.view'), 403, 'No autorizado para esta acción.');

        $engineers = $site->engineers()
            ->with('roles')
            ->get()
            ->map(fn ($u) => [
                'id'          => $u->id,
                'name'        => $u->name,
                'email'       => $u->email,
                'is_active'   => $u->is_active,
                'roles'       => $u->roles->pluck('name'),
                'assigned_at' => $u->pivot->created_at,
            ]);

        return response()->json($engineers);
    }

    public function store(Request $request, Client $client, Site $site): JsonResponse
    {
        abort_unless($site->client_id === $client->id, 404);
        $this->authorizeSite($request, $site);
        abort_unless($request->user()->can('site-engineers.assign'), 403, 'No autorizado para esta acción.');

        $request->validate(['user_id' => 'required|exists:users,id']);

        $user = User::findOrFail($request->user_id);

        if (! $user->hasRole('ingeniero')) {
            return response()->json(['message' => 'El usuario debe tener el rol de Ingeniero para ser asignado.'], 422);
        }

        if ($site->engineers()->where('user_id', $user->id)->exists()) {
            return response()->json(['message' => 'El ingeniero ya está asignado a este sitio.'], 422);
        }

        $site->engineers()->attach($user->id);

        return response()->json([
            'message' => 'Ingeniero asignado al sitio correctamente.',
            'user'    => [
                'id'        => $user->id,
                'name'      => $user->name,
                'email'     => $user->email,
                'is_active' => $user->is_active,
                'roles'     => $user->fresh()->roles->pluck('name'),
            ],
        ], 201);
    }

    public function destroy(Request $request, Client $client, Site $site, User $user): JsonResponse
    {
        abort_unless($site->client_id === $client->id, 404);
        $this->authorizeSite($request, $site);
        abort_unless($request->user()->can('site-engineers.remove'), 403, 'No autorizado para esta acción.');

        if (! $site->engineers()->where('user_id', $user->id)->exists()) {
            return response()->json(['message' => 'El ingeniero no está asignado a este sitio.'], 404);
        }

        $site->engineers()->detach($user->id);

        return response()->json(['message' => 'Ingeniero removido del sitio.']);
    }

    public function candidates(Request $request, Client $client, Site $site): JsonResponse
    {
        abort_unless($site->client_id === $client->id, 404);
        $this->authorizeSite($request, $site);
        abort_unless($request->user()->can('site-engineers.view'), 403, 'No autorizado para esta acción.');

        $assigned = $site->engineers()->pluck('users.id');

        $users = User::where('is_active', true)
            ->whereNotIn('id', $assigned)
            ->role('ingeniero')
            ->when($request->search, fn ($q) => $q->where('name', 'ilike', "%{$request->search}%")
                ->orWhere('email', 'ilike', "%{$request->search}%"))
            ->limit(20)
            ->get(['id', 'name', 'email']);

        return response()->json($users);
    }
}
