<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientEngineerController extends Controller
{
    /** El admin-cliente sólo gestiona ingenieros de sus propios clientes. */
    private function authorizeClient(Request $request, Client $client): void
    {
        $user = $request->user();
        if ($user->hasAnyRole(['superadmin', 'admin'])) return;
        if ($user->hasRole('admin-cliente') && $client->admins()->where('users.id', $user->id)->exists()) return;
        abort(403, 'No tienes acceso a este cliente.');
    }

    public function index(Request $request, Client $client): JsonResponse
    {
        $this->authorizeClient($request, $client);
        abort_unless($request->user()->can('client-engineers.view'), 403, 'No autorizado para esta acción.');

        $engineers = $client->engineers()
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

    public function store(Request $request, Client $client): JsonResponse
    {
        $this->authorizeClient($request, $client);
        abort_unless($request->user()->can('client-engineers.assign'), 403, 'No autorizado para esta acción.');

        $request->validate(['user_id' => 'required|exists:users,id']);

        $user = User::findOrFail($request->user_id);

        if (! $user->hasRole('ingeniero')) {
            return response()->json(['message' => 'El usuario debe tener el rol de Ingeniero para ser asignado.'], 422);
        }

        if ($client->engineers()->where('user_id', $user->id)->exists()) {
            return response()->json(['message' => 'El ingeniero ya está asignado a este cliente.'], 422);
        }

        $client->engineers()->attach($user->id);

        return response()->json([
            'message' => 'Ingeniero asignado al cliente correctamente.',
            'user'    => [
                'id'        => $user->id,
                'name'      => $user->name,
                'email'     => $user->email,
                'is_active' => $user->is_active,
                'roles'     => $user->roles->pluck('name'),
            ],
        ], 201);
    }

    public function destroy(Request $request, Client $client, User $user): JsonResponse
    {
        $this->authorizeClient($request, $client);
        abort_unless($request->user()->can('client-engineers.remove'), 403, 'No autorizado para esta acción.');

        if (! $client->engineers()->where('user_id', $user->id)->exists()) {
            return response()->json(['message' => 'El ingeniero no está asignado a este cliente.'], 404);
        }

        $client->engineers()->detach($user->id);

        return response()->json(['message' => 'Ingeniero removido del cliente.']);
    }

    public function candidates(Request $request, Client $client): JsonResponse
    {
        $this->authorizeClient($request, $client);
        abort_unless($request->user()->can('client-engineers.view'), 403, 'No autorizado para esta acción.');

        $assigned = $client->engineers()->pluck('users.id');

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
