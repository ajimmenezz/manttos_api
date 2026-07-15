<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\TempPasswordMail;
use App\Models\User;
use App\Services\MailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('users.view'), 403, 'No autorizado para esta acción.');

        $users = User::with('roles')
            // ?archived=1 → solo los archivados (papelera); por defecto solo activos.
            ->when($request->boolean('archived'), fn ($q) => $q->onlyTrashed())
            ->when($request->search, fn ($q) => $q->where('name', 'ilike', "%{$request->search}%")
                ->orWhere('email', 'ilike', "%{$request->search}%"))
            ->when($request->role, fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('name', $request->role)))
            ->orderBy('name')
            ->paginate($request->per_page ?? 15);

        // Normalize roles to array of strings, consistent with auth endpoint
        $users->getCollection()->transform(fn ($user) => $this->serializeUser($user));

        return response()->json($users);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('users.create'), 403, 'No autorizado para esta acción.');

        $request->validate([
            'name'  => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'roles' => 'nullable|array',
            'roles.*' => 'exists:roles,name',
            'telegram_username' => 'nullable|string|max:80',
            'whatsapp_number'   => 'nullable|string|max:40',
        ]);

        $identity = $this->messagingIdentity($request, null);
        $tempPassword = Str::password(12);

        $user = User::create([
            'name'                 => $request->name,
            'email'                => $request->email,
            'password'             => $tempPassword,
            'must_change_password' => true,
            'is_active'            => true,
            'created_by'           => $request->user()->id,
            'telegram_username'    => $identity['telegram_username'],
            'whatsapp_number'      => $identity['whatsapp_number'],
        ]);

        if ($request->roles) {
            $user->syncRoles($request->roles);
        }

        $loginUrl = rtrim(config('app.frontend_url', config('app.url')), '/') . '/login';
        $mailResult = MailService::send(
            new TempPasswordMail($user->name, $user->email, $tempPassword, $loginUrl),
            $user->email,
            $user->name,
        );

        $response = [
            'message'       => 'Usuario creado correctamente.',
            'user'          => $this->serializeUser($user->load('roles')),
            'temp_password' => $tempPassword,
        ];

        if (!$mailResult['sent']) {
            $response['email_preview'] = $mailResult['preview'];
        }

        return response()->json($response, 201);
    }

    public function show(User $user): JsonResponse
    {
        abort_unless(auth()->user()->can('users.view'), 403, 'No autorizado para esta acción.');

        return response()->json($this->serializeUser($user->load('roles', 'permissions')));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->can('users.edit'), 403, 'No autorizado para esta acción.');

        $request->validate([
            'name'    => 'required|string|max:255',
            'email'   => "required|email|unique:users,email,{$user->id}",
            'roles'   => 'nullable|array',
            'roles.*' => 'exists:roles,name',
            'telegram_username' => 'nullable|string|max:80',
            'whatsapp_number'   => 'nullable|string|max:40',
        ]);

        $user->update(array_merge(
            $request->only('name', 'email'),
            $this->messagingIdentity($request, $user->id),
        ));

        if ($request->has('roles')) {
            $user->syncRoles($request->roles ?? []);
        }

        return response()->json([
            'message' => 'Usuario actualizado correctamente.',
            'user'    => $this->serializeUser($user->fresh('roles')),
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        // Archivar = baja lógica (soft delete). Requiere permiso de archivado.
        abort_unless($request->user()->can('users.archive'), 403, 'No autorizado para esta acción.');

        // Única restricción: el superadministrador inicial nunca se archiva.
        abort_if($user->id === 1, 403, 'No se puede archivar al superadministrador inicial.');

        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'No puedes archivar tu propia cuenta.'], 422);
        }

        $user->tokens()->delete();  // revoca sesiones y llaves de API
        $user->delete();            // archivar (soft delete → deleted_at); reversible

        return response()->json(['message' => 'Usuario archivado.']);
    }

    public function restore(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->can('users.archive'), 403, 'No autorizado para esta acción.');

        $user->restore();

        return response()->json(['message' => 'Usuario restaurado.', 'user' => $this->serializeUser($user->fresh('roles'))]);
    }

    public function toggleStatus(User $user): JsonResponse
    {
        abort_unless(auth()->user()->can('users.toggle-status'), 403, 'No autorizado para esta acción.');

        if ($user->hasRole('superadmin')) {
            return response()->json(['message' => 'No se puede desactivar al superadministrador.'], 403);
        }

        $user->update(['is_active' => ! $user->is_active]);

        $status = $user->is_active ? 'activado' : 'desactivado';

        return response()->json(['message' => "Usuario {$status} correctamente.", 'user' => $user]);
    }

    public function sendTempPassword(User $user): JsonResponse
    {
        abort_unless(auth()->user()->can('users.send-temp-password'), 403, 'No autorizado para esta acción.');

        $tempPassword = Str::password(12);

        $user->update([
            'password'             => Hash::make($tempPassword),
            'must_change_password' => true,
        ]);

        $loginUrl   = rtrim(config('app.frontend_url', config('app.url')), '/') . '/login';
        $mailResult = MailService::send(
            new TempPasswordMail($user->name, $user->email, $tempPassword, $loginUrl),
            $user->email,
            $user->name,
        );

        $response = [
            'message'       => $mailResult['sent']
                ? 'Contraseña temporal enviada por correo.'
                : 'Contraseña temporal generada. El correo no está configurado.',
            'temp_password' => $tempPassword,
        ];

        if (!$mailResult['sent']) {
            $response['email_preview'] = $mailResult['preview'];
        }

        return response()->json($response);
    }

    public function assignPermissions(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->can('users.assign-permissions'), 403, 'No autorizado para esta acción.');

        $request->validate([
            'permissions'   => 'required|array',
            'permissions.*' => 'exists:permissions,name',
        ]);

        $user->syncPermissions($request->permissions);

        return response()->json([
            'message' => 'Permisos asignados correctamente.',
            'user'    => $this->serializeUser($user->load('roles', 'permissions')),
        ]);
    }

    /**
     * Genera un enlace de acceso (definir contraseña) para copiar y compartir por el
     * medio que sea (WhatsApp, etc.), sin depender del envío de correo. Reutiliza el
     * mecanismo de recuperación de contraseña.
     */
    public function accessLink(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->can('users.send-temp-password'), 403, 'No autorizado para esta acción.');

        $token = PasswordBroker::broker()->createToken($user);
        $url = rtrim(config('app.frontend_url', config('app.url')), '/')
            . '/reset-password?token=' . $token . '&email=' . urlencode($user->email);

        return response()->json([
            'url'     => $url,
            'message' => 'Enlace de acceso generado.',
        ]);
    }

    private function serializeUser(User $user): array
    {
        return [
            'id'                   => $user->id,
            'name'                 => $user->name,
            'email'                => $user->email,
            'is_active'            => $user->is_active,
            'must_change_password' => $user->must_change_password,
            'last_login_at'        => $user->last_login_at,
            'created_by'           => $user->created_by,
            'telegram_username'    => $user->telegram_username,
            'whatsapp_number'      => $user->whatsapp_number,
            'roles'                => $user->roles->pluck('name'),
            'permissions'          => $user->relationLoaded('permissions')
                                        ? $user->permissions->pluck('name')
                                        : [],
        ];
    }

    /**
     * Normaliza la identidad de mensajería (Telegram sin @/minúsculas; WhatsApp solo
     * dígitos) y valida que no la use ya otro usuario.
     *
     * @return array{telegram_username:?string, whatsapp_number:?string}
     */
    private function messagingIdentity(Request $request, ?int $ignoreId): array
    {
        $tg = ltrim(strtolower(trim((string) $request->input('telegram_username'))), '@') ?: null;
        $wa = preg_replace('/\D+/', '', (string) $request->input('whatsapp_number')) ?: null;

        if ($tg && User::where('telegram_username', $tg)->where('id', '!=', $ignoreId)->exists()) {
            abort(422, 'Ese usuario de Telegram ya está asignado a otra persona.');
        }
        if ($wa && User::where('whatsapp_number', $wa)->where('id', '!=', $ignoreId)->exists()) {
            abort(422, 'Ese número de WhatsApp ya está asignado a otra persona.');
        }

        return ['telegram_username' => $tg, 'whatsapp_number' => $wa];
    }
}
