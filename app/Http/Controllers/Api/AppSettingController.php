<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\TempPasswordMail;
use App\Models\AppSetting;
use App\Services\MailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppSettingController extends Controller
{
    private const SMTP_PASSWORD_PLACEHOLDER = '__unchanged__';

    /** GET /settings/public — sin autenticación */
    public function publicIndex(): JsonResponse
    {
        $map = AppSetting::allAsMap();
        unset($map['smtp_password'], $map['smtp_username']);
        return response()->json($map);
    }

    /** GET /settings — autenticado */
    public function index(): JsonResponse
    {
        abort_unless(request()->user()->can('config.manage'), 403);

        $map = AppSetting::allAsMap();

        // Enmascarar la contraseña SMTP
        if (!empty($map['smtp_password'])) {
            $map['smtp_password'] = self::SMTP_PASSWORD_PLACEHOLDER;
        }

        return response()->json($map);
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
            'smtp_host'       => 'sometimes|nullable|string|max:255',
            'smtp_port'       => 'sometimes|nullable|integer|min:1|max:65535',
            'smtp_encryption' => 'sometimes|nullable|string|in:tls,ssl,none',
            'smtp_username'   => 'sometimes|nullable|string|max:255',
            'smtp_password'   => 'sometimes|nullable|string|max:500',
            'smtp_from_email' => 'sometimes|nullable|email|max:255',
            'smtp_from_name'  => 'sometimes|nullable|string|max:100',
        ]);

        foreach ($data as $key => $value) {
            // No sobreescribir la contraseña si viene el placeholder
            if ($key === 'smtp_password' && $value === self::SMTP_PASSWORD_PLACEHOLDER) {
                continue;
            }
            AppSetting::setValue($key, $value !== null ? (string) $value : null);
        }

        $map = AppSetting::allAsMap();
        if (!empty($map['smtp_password'])) {
            $map['smtp_password'] = self::SMTP_PASSWORD_PLACEHOLDER;
        }

        return response()->json($map);
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
