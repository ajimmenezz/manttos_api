<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\ResetPasswordMail;
use App\Models\User;
use App\Services\MailService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Credenciales incorrectas.'], 401);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'Tu cuenta está desactivada. Contacta al administrador.'], 403);
        }

        $user->update(['last_login_at' => now()]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'token'                => $token,
            'must_change_password' => $user->must_change_password,
            'user'                 => $this->userPayload($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada correctamente.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->userPayload($request->user()));
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        // En primer login no se requiere la contraseña actual
        $rules = [
            'password' => ['required', 'confirmed', PasswordRule::min(8)->mixedCase()->numbers()],
        ];

        if (! $user->must_change_password) {
            $rules['current_password'] = 'required|string';
        }

        $request->validate($rules);

        if (! $user->must_change_password) {
            if (! Hash::check($request->current_password, $user->password)) {
                return response()->json(['message' => 'La contraseña actual es incorrecta.'], 422);
            }
        }

        $user->update([
            'password'             => $request->password,
            'must_change_password' => false,
        ]);

        return response()->json(['message' => 'Contraseña actualizada correctamente.']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email|exists:users,email']);

        // Si el SMTP no está configurado, no revelamos ningún enlace — ruta pública
        if (!MailService::isConfigured()) {
            return response()->json([
                'message' => 'El sistema de correo no está configurado. Contacta al administrador para recuperar tu acceso.',
            ], 503);
        }

        $user  = User::where('email', $request->email)->first();
        $token = Password::broker()->createToken($user);

        $frontendUrl = rtrim(config('app.frontend_url', config('app.url')), '/');
        $resetUrl    = $frontendUrl . '/reset-password?token=' . $token
                     . '&email=' . urlencode($user->email);

        $result = MailService::send(
            new ResetPasswordMail($user->name, $resetUrl),
            $user->email,
            $user->name,
        );

        if (!$result['sent']) {
            // Error de envío (SMTP mal configurado, credenciales incorrectas, etc.)
            return response()->json([
                'message' => 'No se pudo enviar el correo de recuperación. Contacta al administrador.',
            ], 503);
        }

        return response()->json([
            'message' => 'Se ha enviado el enlace de recuperación a ' . $user->email . '.',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token'    => 'required|string',
            'email'    => 'required|email',
            'password' => ['required', 'confirmed', PasswordRule::min(8)->mixedCase()->numbers()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password'             => $password,
                    'must_change_password' => false,
                ])->setRememberToken(Str::random(60));

                $user->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => 'El token es inválido o ha expirado.'], 422);
        }

        return response()->json(['message' => 'Contraseña restablecida correctamente.']);
    }

    private function userPayload(User $user): array
    {
        $user->load('roles');

        // Superadmin gets all permissions so the frontend can render everything
        if ($user->hasRole('superadmin')) {
            $permissions = \Spatie\Permission\Models\Permission::pluck('name');
        } else {
            $permissions = $user->getAllPermissions()->pluck('name')->unique()->values();
        }

        return [
            'id'                   => $user->id,
            'name'                 => $user->name,
            'email'                => $user->email,
            'must_change_password' => $user->must_change_password,
            'is_active'            => $user->is_active,
            'roles'                => $user->roles->pluck('name'),
            'permissions'          => $permissions,
            'last_login_at'        => $user->last_login_at,
        ];
    }
}
