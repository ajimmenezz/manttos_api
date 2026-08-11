<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ImpersonationLog;
use App\Models\User;
use App\Support\UserPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Suplantación de usuario (solo superadmin, solo web): permite ver y operar el sistema
 * con los datos y privilegios de otro usuario NO superadmin, para diagnosticar problemas
 * sin pedir sus credenciales. Emite un token Sanctum para el usuario objetivo (con
 * expiración de guardarraíl) y deja el original en el cliente para poder volver. Todo
 * queda en `impersonation_logs`. No desplaza la sesión real del usuario suplantado.
 */
class ImpersonationController extends Controller
{
    /** Vida máxima del token suplantado (guardarraíl si no se cierra la sesión). */
    private const TTL_HOURS = 12;

    /** GET /impersonate/users — candidatos a suplantar (activos, no superadmin, no uno mismo). */
    public function users(Request $request): JsonResponse
    {
        $me = $request->user();
        abort_unless($me->hasRole('superadmin'), 403, 'Solo un superadministrador puede suplantar.');

        $users = User::query()
            ->where('is_active', true)
            ->where('id', '!=', $me->id)
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'superadmin'))
            ->with('roles:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $u) => [
                'id'    => $u->id,
                'name'  => $u->name,
                'email' => $u->email,
                'roles' => $u->roles->pluck('name'),
            ]);

        return response()->json($users);
    }

    /** POST /impersonate/{user} — inicia la suplantación. Devuelve el token del objetivo. */
    public function start(Request $request, User $user): JsonResponse
    {
        $me = $request->user();
        abort_unless($me->hasRole('superadmin'), 403, 'Solo un superadministrador puede suplantar.');

        // No se puede suplantar a uno mismo, a un superadmin, ni a un usuario inactivo.
        abort_if($user->id === $me->id, 422, 'No puedes suplantarte a ti mismo.');
        abort_if($user->hasRole('superadmin'), 403, 'No se puede suplantar a un superadministrador.');
        abort_unless($user->is_active, 422, 'El usuario está inactivo.');

        // Token del usuario objetivo con expiración; nombre marcado para identificarlo.
        $token = $user->createToken('impersonation', ['*'], now()->addHours(self::TTL_HOURS));

        ImpersonationLog::create([
            'impersonator_id' => $me->id,
            'impersonated_id' => $user->id,
            'token_id'        => $token->accessToken->getKey(),
            'ip'              => $request->ip(),
            'started_at'      => now(),
        ]);

        app(\App\Support\ActivityLogger::class)->log('auth', 'impersonate_start', "Suplantó a {$user->name}", [
            'source' => 'auth', 'user_id' => $me->id,
            'subject_type' => 'User', 'subject_id' => $user->id, 'subject_label' => $user->name,
        ]);

        return response()->json([
            'token'        => $token->plainTextToken,
            'user'         => UserPayload::for($user),
            'impersonator' => ['id' => $me->id, 'name' => $me->name, 'email' => $me->email],
        ]);
    }

    /**
     * POST /impersonate/stop — termina la suplantación. Se llama CON el token suplantado:
     * cierra la bitácora y revoca ese token. Si por alguna razón se llama con otro token,
     * cierra la última sesión abierta de ese usuario suplantado (best-effort).
     */
    public function stop(Request $request): JsonResponse
    {
        $user  = $request->user();
        $token = $user->currentAccessToken();
        $tokenId = $token?->getKey();

        $log = ImpersonationLog::query()
            ->whereNull('ended_at')
            ->when($tokenId, fn ($q) => $q->where('token_id', $tokenId))
            ->when(! $tokenId, fn ($q) => $q->where('impersonated_id', $user->id))
            ->orderByDesc('id')
            ->first();

        abort_if(! $log, 422, 'No hay una suplantación activa para esta sesión.');

        $log->update(['ended_at' => now()]);

        app(\App\Support\ActivityLogger::class)->log('auth', 'impersonate_stop', 'Terminó la suplantación', [
            'source' => 'auth',
            'subject_type' => 'User', 'subject_id' => $log->impersonated_id,
        ]);

        // Revocar el token suplantado para que no quede vivo tras volver.
        if ($token && method_exists($token, 'delete')) {
            $token->delete();
        }

        return response()->json(['message' => 'Suplantación finalizada.']);
    }
}
