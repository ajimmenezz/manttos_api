<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteUserController extends Controller
{
    public function index(Client $client, Site $site): JsonResponse
    {
        abort_unless($site->client_id === $client->id, 404);

        $admins = $site->admins()
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

        return response()->json($admins);
    }

    public function store(Request $request, Client $client, Site $site): JsonResponse
    {
        abort_unless($site->client_id === $client->id, 404);

        $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $user = User::findOrFail($request->user_id);

        if ($site->admins()->where('user_id', $user->id)->exists()) {
            return response()->json(['message' => 'El usuario ya está asignado a este sitio.'], 422);
        }

        if (! $user->hasRole('admin-sitio')) {
            return response()->json(['message' => 'El usuario debe tener el rol de Administrador de Sitio para ser asignado.'], 422);
        }

        $site->admins()->attach($user->id);

        return response()->json([
            'message' => 'Administrador asignado al sitio correctamente.',
            'user'    => [
                'id'        => $user->id,
                'name'      => $user->name,
                'email'     => $user->email,
                'is_active' => $user->is_active,
                'roles'     => $user->fresh()->roles->pluck('name'),
            ],
        ], 201);
    }

    public function destroy(Client $client, Site $site, User $user): JsonResponse
    {
        abort_unless($site->client_id === $client->id, 404);

        if (! $site->admins()->where('user_id', $user->id)->exists()) {
            return response()->json(['message' => 'El usuario no está asignado a este sitio.'], 404);
        }

        $site->admins()->detach($user->id);

        return response()->json(['message' => 'Administrador removido del sitio.']);
    }

    public function candidates(Request $request, Client $client, Site $site): JsonResponse
    {
        abort_unless($site->client_id === $client->id, 404);

        $assigned = $site->admins()->pluck('users.id');

        $users = User::where('is_active', true)
            ->whereNotIn('id', $assigned)
            ->role('admin-sitio')
            ->when($request->search, fn ($q) => $q->where('name', 'ilike', "%{$request->search}%")
                ->orWhere('email', 'ilike', "%{$request->search}%"))
            ->limit(20)
            ->get(['id', 'name', 'email']);

        return response()->json($users);
    }
}
