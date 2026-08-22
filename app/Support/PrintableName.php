<?php

namespace App\Support;

use Carbon\Carbon;
use Throwable;

/**
 * Nombre del archivo de un imprimible.
 *
 * Los PDF se archivan y se mandan por correo, así que el nombre es parte del
 * entregable: «bitacora-de-eventos.pdf» obliga a abrirlo para saber de qué sitio y de
 * qué periodo es. El formato acordado es
 *
 *     Bitacora_Eventos_Hyatt_Zilara_Riviera_Maya_del_20260801_al_20260831.pdf
 *
 * Sin espacios (se sustituyen por guion bajo) y con las fechas compactas, que ordenan
 * bien alfabéticamente dentro de una carpeta.
 *
 * Lo arma el SERVIDOR, no el front: es quien conoce el sitio y el periodo ya resueltos,
 * y así la regla no se repite en cada pantalla. El front lo toma de `Content-Disposition`.
 */
class PrintableName
{
    /** $from/$to aceptan string o fecha: los controladores manejan ambas. */
    public static function build(string $prefix, ?string $subject = null, mixed $from = null, mixed $to = null): string
    {
        $parts = [$prefix];

        if ($subject !== null && trim($subject) !== '') $parts[] = trim($subject);

        $desde = self::compact($from);
        $hasta = self::compact($to);

        if ($desde && $hasta) {
            $parts[] = 'del ' . $desde . ' al ' . $hasta;
        } elseif ($desde || $hasta) {
            $parts[] = $desde ?: $hasta;
        }

        $name = preg_replace('/\s+/u', '_', trim(implode(' ', $parts)));

        // Lo que ningún sistema de archivos acepta. Los acentos se conservan: el
        // encabezado los codifica en UTF-8 (RFC 5987) y los navegadores actuales los
        // respetan; forzar ASCII dejaría nombres peores («Cancn»).
        $name = preg_replace('#[/\\\\:*?"<>|\x00-\x1F]+#u', '', (string) $name);
        $name = trim((string) preg_replace('/_+/u', '_', $name), '_');

        return ($name === '' ? 'documento' : $name) . '.pdf';
    }

    /** 2026-08-01 → 20260801 */
    private static function compact(mixed $date): ?string
    {
        if ($date === null || $date === '') return null;

        try {
            return ($date instanceof \DateTimeInterface ? Carbon::instance($date) : Carbon::parse((string) $date))->format('Ymd');
        } catch (Throwable) {
            return null;   // una fecha rara no debe impedir la descarga
        }
    }
}
