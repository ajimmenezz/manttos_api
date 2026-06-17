<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\TempPasswordMail;
use App\Models\AppSetting;
use App\Services\MailService;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AppSettingController extends Controller
{
    private const SMTP_PASSWORD_PLACEHOLDER = '__unchanged__';

    /**
     * Tenant a administrar. Por defecto es el dominio del front, PERO quien tiene
     * `config.manage` (superadmin) puede pasar `?tenant=` / body `tenant` para
     * editar el tema de CUALQUIER dominio desde un solo lugar.
     */
    private function resolveTenant(Request $request): string
    {
        $explicit = $request->input('tenant', $request->query('tenant'));
        if ($explicit && $request->user()?->can('config.manage')) {
            $host = strtolower(trim((string) $explicit));
            $host = preg_replace('/:\d+$/', '', $host);
            return $host !== '' ? $host : AppSetting::DEFAULT_TENANT;
        }
        return Tenant::fromRequest($request);
    }

    /** GET /settings/tenants — dominios con configuración (superadmin). */
    public function tenants(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('config.manage'), 403);

        $list = DB::table('app_settings')->distinct()->pluck('tenant')->filter()->values();
        if (! $list->contains(AppSetting::DEFAULT_TENANT)) {
            $list->prepend(AppSetting::DEFAULT_TENANT);
        }

        return response()->json($list->values());
    }

    /** Decodifica el campo `theme` (JSON string) a objeto para la respuesta. */
    private function decodeTheme(array $map): array
    {
        if (isset($map['theme']) && is_string($map['theme'])) {
            $decoded = json_decode($map['theme'], true);
            $map['theme'] = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
        }
        return $map;
    }

    /** GET /settings/public — sin autenticación. Tema resuelto por dominio. */
    public function publicIndex(Request $request): JsonResponse
    {
        $map = AppSetting::allAsMap(Tenant::fromRequest($request));
        unset($map['smtp_password'], $map['smtp_username']);
        return response()->json($this->decodeTheme($map));
    }

    /** GET /settings — autenticado. Edita el tema del dominio actual. */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('config.manage'), 403);

        $map = AppSetting::allAsMap($this->resolveTenant($request));

        // Enmascarar la contraseña SMTP
        if (!empty($map['smtp_password'])) {
            $map['smtp_password'] = self::SMTP_PASSWORD_PLACEHOLDER;
        }

        return response()->json($this->decodeTheme($map));
    }

    /** PUT /settings */
    public function update(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('config.manage'), 403);

        $data = $request->validate([
            'app_name'        => 'sometimes|string|max:80',
            'logo_url'        => 'sometimes|nullable|string|max:500',
            'login_bg_url'    => 'sometimes|nullable|string|max:500',
            'color_preset'    => 'sometimes|string|in:blue,indigo,violet,green,orange,rose',
            'theme'           => 'sometimes|nullable|array',
            'smtp_host'       => 'sometimes|nullable|string|max:255',
            'smtp_port'       => 'sometimes|nullable|integer|min:1|max:65535',
            'smtp_encryption' => 'sometimes|nullable|string|in:tls,ssl,none',
            'smtp_username'   => 'sometimes|nullable|string|max:255',
            'smtp_password'   => 'sometimes|nullable|string|max:500',
            'smtp_from_email' => 'sometimes|nullable|email|max:255',
            'smtp_from_name'  => 'sometimes|nullable|string|max:100',
        ]);

        // El branding y el tema son por dominio; el SMTP es global (tenant default).
        $tenant      = $this->resolveTenant($request);
        $perTenant   = ['app_name', 'logo_url', 'login_bg_url', 'color_preset', 'theme'];

        foreach ($data as $key => $value) {
            // No sobreescribir la contraseña si viene el placeholder
            if ($key === 'smtp_password' && $value === self::SMTP_PASSWORD_PLACEHOLDER) {
                continue;
            }

            $stored = $key === 'theme'
                ? ($value !== null ? json_encode($value) : null)
                : ($value !== null ? (string) $value : null);

            $scope = in_array($key, $perTenant, true) ? $tenant : AppSetting::DEFAULT_TENANT;
            AppSetting::setValue($key, $stored, $scope);
        }

        $map = AppSetting::allAsMap($tenant);
        if (!empty($map['smtp_password'])) {
            $map['smtp_password'] = self::SMTP_PASSWORD_PLACEHOLDER;
        }

        return response()->json($this->decodeTheme($map));
    }

    /** POST /settings/test-mail — envía un correo de prueba al usuario autenticado */
    public function testMail(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('config.manage'), 403);

        $user = $request->user();

        $loginUrl   = rtrim(config('app.frontend_url', config('app.url')), '/') . '/login';
        $result = MailService::send(
            new TempPasswordMail($user->name, $user->email, 'Prueba-123!', $loginUrl),
            $user->email,
            $user->name,
        );

        if ($result['sent']) {
            return response()->json(['message' => 'Correo de prueba enviado a ' . $user->email . '.']);
        }

        return response()->json([
            'message'       => isset($result['error'])
                ? 'Error al enviar: ' . $result['error']
                : 'SMTP no configurado.',
            'email_preview' => $result['preview'],
        ], isset($result['error']) ? 422 : 200);
    }
}
