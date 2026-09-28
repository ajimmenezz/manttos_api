<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Hora de ejecución/ocurrencia perdida.
 *
 * Hasta 2026-09 el campo de fecha mandaba solo 'YYYY-MM-DD' y se guardaba a las 00:00
 * UTC, así que casi todo lo capturado por ingenieros (que tienen el permiso de fijar la
 * fecha) quedó sin hora: la bitácora decía 00:00, y las pantallas que convierten a hora
 * local lo enseñaban a las 18:00 DEL DÍA ANTERIOR.
 *
 * 1. Si la fecha es el mismo día en que se registró, la hora real es la del registro
 *    (`created_at`): se capturó ese día y el formulario solo perdió la hora. El día se
 *    compara en hora de México y en UTC, porque la web mandaba el día UTC (después de las
 *    18:00 ya era «mañana»). En capturas sin conexión es la hora en que se sincronizó.
 * 2. El resto (fechas de otro día, importaciones de ADIST) no tiene hora que recuperar:
 *    se deja en la medianoche LOCAL de ese día, para que el DÍA salga bien en todas las
 *    pantallas.
 *
 * Solo toca lo que está exactamente a las 00:00:00 UTC. Se corre una vez (es migración):
 * después del despliegue una captura a las 18:00 en punto de México también cae ahí.
 */
return new class extends Migration
{
    private const TZ = 'America/Mexico_City';

    public function up(): void
    {
        foreach ([
            ['maintenance_activities', 'performed_at', 'source_ref IS NULL'],
            ['events',                 'occurred_at',  'TRUE'],
        ] as [$table, $col, $fromCapture]) {
            $mxDay  = "((created_at AT TIME ZONE 'UTC') AT TIME ZONE '" . self::TZ . "')::date";
            $sameDay = DB::update("
                UPDATE {$table} SET {$col} = created_at
                WHERE {$col}::time = '00:00:00' AND {$fromCapture}
                  AND {$col}::date IN ({$mxDay}, created_at::date)
            ");

            $localMidnight = DB::update("
                UPDATE {$table}
                SET {$col} = ({$col}::date::timestamp AT TIME ZONE '" . self::TZ . "') AT TIME ZONE 'UTC'
                WHERE {$col}::time = '00:00:00'
            ");

            Log::info("Hora de {$table}.{$col}: {$sameDay} con la hora del registro, {$localMidnight} a medianoche local.");
        }
    }

    public function down(): void
    {
        // Irreversible: no se guardó el valor anterior (que era medianoche UTC).
    }
};
