<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\TempPasswordMail;
use App\Models\User;
use App\Services\MailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    public function destroy(Request $request, User $user): JsonResponse
    {
        // Solo el superadministrador puede eliminar usuarios (baja lógica).
        abort_unless($request->user()->hasRole('superadmin'), 403, 'Solo el superadministrador puede eliminar usuarios.');

        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'No puedes eliminar tu propia cuenta.'], 422);
        }

        if ($user->hasRole('superadmin')) {
            return response()->json(['message' => 'No se puede eliminar al superadministrador.'], 403);
        }

        // Con registros asociados NO se elimina: solo puede inactivarse (dar de baja).
        $records = $this->associatedRecordLabels($user);
        if (! empty($records)) {
            return response()->json([
                'message'     => 'El usuario tiene registros asociados (' . implode(', ', $records) . '), '
                    . 'por eso no puede eliminarse. Usa "Dar de baja" para inactivarlo.',
                'has_records' => true,
            ], 422);
        }

        $user->tokens()->delete();  // revoca sesiones y llaves de API
        $user->delete();            // baja lógica (soft delete → deleted_at)

        return response()->json(['message' => 'Usuario eliminado correctamente.']);
    }

    /**
     * Devuelve las categorías de registros asociados al usuario (huella operativa).
     * Si hay al menos una, el usuario no debe eliminarse, solo inactivarse.
     *
     * @return string[]
     */
    private function associatedRecordLabels(User $user): array
    {
        $id     = $user->id;
        $labels = [];

        if (DB::table('maintenance_activities')->where('user_id', $id)->exists()) {
            $labels[] = 'actividades registradas';
        }
        if (DB::table('maintenance_engineers')->where('user_id', $id)->exists()) {
            $labels[] = 'mantenimientos asignados';
        }
        if (DB::table('events')->where('created_by', $id)->orWhere('assigned_to', $id)->exists()) {
            $labels[] = 'eventos';
        }
        if (DB::table('event_status_history')->where('user_id', $id)->exists()) {
            $labels[] = 'historial de eventos';
        }
        if (DB::table('device_schedules')->where('created_by', $id)->exists()) {
            $labels[] = 'programaciones de dispositivos';
        }
        if (DB::table('client_user')->where('user_id', $id)->exists()
            || DB::table('site_user')->where('user_id', $id)->exists()) {
            $labels[] = 'administración de clientes/sitios';
        }
        if (DB::table('client_engineers')->where('user_id', $id)->exists()
            || DB::table('site_engineers')->where('user_id', $id)->exists()) {
            $labels[] = 'asignaciones de ingeniería';
        }

        // Registros creados por el usuario en el sistema (created_by / uploaded_by).
        $createdByTables = ['clients', 'sites', 'directories', 'devices', 'maintenances',
            'system_fields', 'floor_plans', 'device_placements', 'event_types'];
        $createdSomething = false;
        foreach ($createdByTables as $table) {
            if (DB::table($table)->where('created_by', $id)->exists()) { $createdSomething = true; break; }
        }
        if (! $createdSomething) {
            $createdSomething = DB::table('maintenance_contract_files')->where('uploaded_by', $id)->exists()
                || DB::table('users')->where('created_by', $id)->exists();
        }
        if ($createdSomething) {
            $labels[] = 'registros creados en el sistema';
        }

        return $labels;
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
