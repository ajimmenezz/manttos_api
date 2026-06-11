<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\TempPasswordMail;
use App\Models\User;
use App\Services\MailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $users = User::with('roles')
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
        $request->validate([
            'name'  => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'roles' => 'nullable|array',
            'roles.*' => 'exists:roles,name',
        ]);

        $tempPassword = Str::password(12);

        $user = User::create([
            'name'                 => $request->name,
            'email'                => $request->email,
            'password'             => $tempPassword,
            'must_change_password' => true,
            'is_active'            => true,
            'created_by'           => $request->user()->id,
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
        return response()->json($this->serializeUser($user->load('roles', 'permissions')));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'name'    => 'required|string|max:255',
            'email'   => "required|email|unique:users,email,{$user->id}",
            'roles'   => 'nullable|array',
            'roles.*' => 'exists:roles,name',
        ]);

        $user->update($request->only('name', 'email'));

        if ($request->has('roles')) {
            $user->syncRoles($request->roles ?? []);
        }

        return response()->json([
            'message' => 'Usuario actualizado correctamente.',
            'user'    => $this->serializeUser($user->fresh('roles')),
        ]);
    }

    public function destroy(User $user): JsonResponse
    {
        if ($user->hasRole('superadmin')) {
            return response()->json(['message' => 'No se puede eliminar al superadministrador.'], 403);
        }

        $user->delete();

        return response()->json(['message' => 'Usuario eliminado correctamente.']);
    }

    public function toggleStatus(User $user): JsonResponse
    {
        if ($user->hasRole('superadmin')) {
            return response()->json(['message' => 'No se puede desactivar al superadministrador.'], 403);
        }

        $user->update(['is_active' => ! $user->is_active]);

        $status = $user->is_active ? 'activado' : 'desactivado';

        return response()->json(['message' => "Usuario {$status} correctamente.", 'user' => $user]);
    }

    public function sendTempPassword(User $user): JsonResponse
    {
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
            'roles'                => $user->roles->pluck('name'),
            'permissions'          => $user->relationLoaded('permissions')
                                        ? $user->permissions->pluck('name')
                                        : [],
        ];
    }
}
