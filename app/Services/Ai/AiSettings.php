<?php

namespace App\Services\Ai;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Crypt;

/**
 * Lee y escribe la configuración ELEGIDA del asistente de IA (proveedor,
 * modelo, API key, overrides de precio). Vive en `app_settings` bajo el tenant
 * default (config global, no por dominio). La API key se guarda cifrada.
 *
 * El catálogo de referencia (proveedores/modelos/precios base) está en
 * config/ai.php; aquí solo se guarda QUÉ eligió el cliente.
 */
class AiSettings
{
    /** Claves en app_settings. */
    private const K_ENABLED   = 'ai_enabled';
    private const K_PROVIDER  = 'ai_provider';
    private const K_MODEL     = 'ai_model';
    private const K_API_KEY   = 'ai_api_key';    // cifrada
    private const K_BASE_URL  = 'ai_base_url';   // override opcional
    private const K_PRICE_IN  = 'ai_price_in';   // override opcional (proveedores editables)
    private const K_PRICE_OUT = 'ai_price_out';

    public const API_KEY_PLACEHOLDER = '__unchanged__';

    /** Mapa crudo de settings (default tenant). */
    private static function map(): array
    {
        return AppSetting::allAsMap(AppSetting::DEFAULT_TENANT);
    }

    public static function enabled(): bool
    {
        return (self::map()[self::K_ENABLED] ?? '0') === '1';
    }

    public static function provider(): string
    {
        return self::map()[self::K_PROVIDER] ?? (string) config('ai.default_provider', 'ollama');
    }

    public static function model(): ?string
    {
        $map = self::map();
        $provider = $map[self::K_PROVIDER] ?? config('ai.default_provider', 'ollama');
        $model    = $map[self::K_MODEL] ?? null;

        if ($model) {
            return $model;
        }

        // Sin modelo guardado → primer modelo recomendado del proveedor.
        $models = (array) config("ai.providers.{$provider}.models", []);
        foreach ($models as $id => $m) {
            if (! empty($m['recommended'])) {
                return $id;
            }
        }

        return array_key_first($models) ?: null;
    }

    /** API key en claro (descifrada) o null. */
    public static function apiKey(): ?string
    {
        $enc = self::map()[self::K_API_KEY] ?? null;
        if (! $enc) {
            return null;
        }
        try {
            return Crypt::decryptString($enc);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function hasApiKey(): bool
    {
        return ! empty(self::map()[self::K_API_KEY] ?? null);
    }

    /**
     * Configuración efectiva en runtime para el proveedor de chat (Fase 2):
     * mezcla el catálogo con los overrides guardados y descifra la key.
     */
    public static function resolved(): array
    {
        $map      = self::map();
        $provider = self::provider();
        $model    = self::model();
        $catalog  = (array) config("ai.providers.{$provider}", []);
        $modelDef = (array) ($catalog['models'][$model] ?? []);

        return [
            'enabled'    => self::enabled(),
            'provider'   => $provider,
            'api_style'  => $catalog['api_style'] ?? 'openai',
            'local'      => (bool) ($catalog['local'] ?? false),
            'base_url'   => $map[self::K_BASE_URL] ?? ($catalog['base_url'] ?? null),
            'model'      => $model,
            'api_key'    => self::apiKey(),
            'price_in'   => isset($map[self::K_PRICE_IN])  ? (float) $map[self::K_PRICE_IN]  : (float) ($modelDef['price_in']  ?? 0),
            'price_out'  => isset($map[self::K_PRICE_OUT]) ? (float) $map[self::K_PRICE_OUT] : (float) ($modelDef['price_out'] ?? 0),
        ];
    }

    /**
     * Persiste la configuración. `$data` puede traer: enabled, provider, model,
     * api_key (o el placeholder para no tocarla), base_url, price_in, price_out.
     */
    public static function save(array $data): void
    {
        if (array_key_exists('enabled', $data)) {
            AppSetting::setValue(self::K_ENABLED, $data['enabled'] ? '1' : '0');
        }
        if (array_key_exists('provider', $data)) {
            AppSetting::setValue(self::K_PROVIDER, (string) $data['provider']);
        }
        if (array_key_exists('model', $data)) {
            AppSetting::setValue(self::K_MODEL, $data['model'] !== null ? (string) $data['model'] : null);
        }
        if (array_key_exists('base_url', $data)) {
            AppSetting::setValue(self::K_BASE_URL, $data['base_url'] !== null ? (string) $data['base_url'] : null);
        }
        if (array_key_exists('price_in', $data)) {
            AppSetting::setValue(self::K_PRICE_IN, $data['price_in'] !== null ? (string) $data['price_in'] : null);
        }
        if (array_key_exists('price_out', $data)) {
            AppSetting::setValue(self::K_PRICE_OUT, $data['price_out'] !== null ? (string) $data['price_out'] : null);
        }

        // API key: cifrar; ignorar el placeholder (no cambiar); '' = borrar.
        if (array_key_exists('api_key', $data)) {
            $key = $data['api_key'];
            if ($key === self::API_KEY_PLACEHOLDER) {
                // no tocar
            } elseif ($key === null || $key === '') {
                AppSetting::setValue(self::K_API_KEY, null);
            } else {
                AppSetting::setValue(self::K_API_KEY, Crypt::encryptString((string) $key));
            }
        }
    }
}
