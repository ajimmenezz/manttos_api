<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gestión de llaves de API personales (Sanctum) para que los clientes construyan
 * su propio front. Las llaves actúan como el usuario que las crea, por lo que
 * heredan sus permisos y su alcance de datos. Vedado a superadmin/admin.
 *
 * Nombre del token: "api:<etiqueta>". Abilities: ['read'] (solo lectura) o
 * ['read','write'] (lectura y escritura).
 */
class DeveloperTokenController extends Controller
{
    private const PREFIX = 'api:';

    private function guard(Request $request): void
    {
        abort_unless($request->user()->canManageApiTokens(), 403,
            'Tu rol no puede gestionar llaves de API.');
    }

    /** Etiqueta legible a partir del nombre del token. */
    private function present($token): array
    {
        return [
            'id'           => $token->id,
            'label'        => preg_replace('/^' . preg_quote(self::PREFIX, '/') . '/', '', $token->name),
            'scope'        => in_array('write', (array) $token->abilities, true) ? 'full' : 'read',
            'last_used_at' => $token->last_used_at,
            'created_at'   => $token->created_at,
        ];
    }

    /** GET /developer/tokens */
    public function index(Request $request): JsonResponse
    {
        $this->guard($request);

        $tokens = $request->user()->tokens()
            ->where('name', 'like', self::PREFIX . '%')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($t) => $this->present($t));

        return response()->json($tokens);
    }

    /** POST /developer/tokens — devuelve el token en claro UNA sola vez. */
    public function store(Request $request): JsonResponse
    {
        $this->guard($request);

        $data = $request->validate([
            'label' => 'required|string|max:60',
            'scope' => 'required|string|in:read,full',
        ]);

        $abilities = $data['scope'] === 'full' ? ['read', 'write'] : ['read'];
        $newToken  = $request->user()->createToken(self::PREFIX . $data['label'], $abilities);

        return response()->json([
            'plain_text_token' => $newToken->plainTextToken,
            'token'            => $this->present($newToken->accessToken),
        ], 201);
    }

    /** DELETE /developer/tokens/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->guard($request);

        $deleted = $request->user()->tokens()
            ->where('id', $id)
            ->where('name', 'like', self::PREFIX . '%')
            ->delete();

        abort_unless($deleted, 404, 'Llave no encontrada.');

        return response()->json(['message' => 'Llave revocada.']);
    }
}
