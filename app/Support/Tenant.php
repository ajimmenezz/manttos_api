<?php

namespace App\Support;

use App\Models\AppSetting;
use Illuminate\Http\Request;

/**
 * Resuelve el tenant (white-label) a partir del dominio del front.
 *
 * El front vive en `mantenimientos.clienteX.com` pero la API puede estar en otro
 * host, así que NO basta el Host de la petición a la API. El front envía el header
 * `X-Tenant-Host` con su propio hostname; como respaldo se usan Origin / Referer
 * y, en última instancia, el Host de la petición.
 */
class Tenant
{
    public static function fromRequest(Request $request): string
    {
        $raw = $request->header('X-Tenant-Host')
            ?: parse_url((string) $request->header('Origin', ''), PHP_URL_HOST)
            ?: parse_url((string) $request->header('Referer', ''), PHP_URL_HOST)
            ?: $request->getHost();

        $host = strtolower(trim((string) $raw));
        $host = preg_replace('/:\d+$/', '', $host); // quitar puerto

        return $host !== '' ? $host : AppSetting::DEFAULT_TENANT;
    }
}
