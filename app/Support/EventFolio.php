<?php

namespace App\Support;

use App\Models\Client;
use Illuminate\Support\Facades\DB;

/**
 * Genera el folio de un evento según la nomenclatura configurada por cliente
 * (clients.event_folio_config), con un contador atómico por cliente/periodo.
 *
 *   config: { prefix, include_year, pad, reset_yearly }
 *   ej.    HILT-2026-0001
 */
class EventFolio
{
    public static function next(Client $client): string
    {
        $cfg         = $client->event_folio_config ?? [];
        // El folio es único GLOBAL: si no hay prefijo configurado se deriva del cliente
        // (short_name o nombre) para que clientes distintos no colisionen con un "EVT" común.
        $prefix      = trim((string) ($cfg['prefix'] ?? '')) ?: self::defaultPrefix($client);
        $includeYear = $cfg['include_year'] ?? true;
        $pad         = max(1, (int) ($cfg['pad'] ?? 4));
        $resetYearly = $cfg['reset_yearly'] ?? true;

        $year   = date('Y');
        $period = $resetYearly ? $year : 'all';

        // Incremento atómico con lock de fila para evitar folios duplicados.
        $seq = DB::transaction(function () use ($client, $period) {
            $row = DB::table('event_folio_counters')
                ->where('client_id', $client->id)
                ->where('period', $period)
                ->lockForUpdate()
                ->first();

            if ($row) {
                $next = $row->last_seq + 1;
                DB::table('event_folio_counters')->where('id', $row->id)
                    ->update(['last_seq' => $next, 'updated_at' => now()]);
            } else {
                $next = 1;
                DB::table('event_folio_counters')->insert([
                    'client_id'  => $client->id,
                    'period'     => $period,
                    'last_seq'   => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $next;
        });

        $parts = [$prefix];
        if ($includeYear) {
            $parts[] = $year;
        }
        $parts[] = str_pad((string) $seq, $pad, '0', STR_PAD_LEFT);

        return implode('-', $parts);
    }

    /** Prefijo por defecto derivado del cliente (alfanumérico, mayúsculas, máx 6). */
    private static function defaultPrefix(Client $client): string
    {
        $base = $client->short_name ?: $client->name;
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $base));
        $clean = substr($clean, 0, 6);
        return $clean !== '' ? $clean : 'EVT';
    }
}
