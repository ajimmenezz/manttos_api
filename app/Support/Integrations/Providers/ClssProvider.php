<?php

namespace App\Support\Integrations\Providers;

use App\Models\Integration;
use App\Support\Integrations\AbstractIntegrationProvider;
use Illuminate\Support\Facades\Http;

/**
 * Conector de LECTURA al tablero CLSS (https://clss.siccob.com.mx, monitoreo de los paneles de incendio de
 * Honeywell). Alimenta el «Reporte CLSS» (ClssReportController): avance de pruebas y ciclos de señal de los
 * paneles, por sitio.
 *
 * El tablero entrega lo YA CALCULADO por su API `/api/v1/*` (llave `clss_…` de solo lectura, se crea allá con
 * `manage.py llave_api crear`). Aquí no se rehace ninguna regla: el ciclo de una falla, la cobertura y el técnico
 * por sesión viven en el tablero, para no tener dos fórmulas que discrepen.
 *
 * Se pregunta por los ids de SITIO de esta plataforma; el tablero los traduce a edificios de CLSS con las
 * equivalencias que confirma su administrador. Un sitio sin equivalencia simplemente no trae datos.
 *
 * No reacciona a eventos de negocio (solo lee).
 */
class ClssProvider extends AbstractIntegrationProvider
{
    /** Lo único que el reporte puede pedir al tablero (ruta relativa a /api/v1/). */
    public const OPERACIONES = ['estado', 'pruebas', 'pruebas/detalle', 'eventos', 'eventos/detalle'];

    public function key(): string { return 'clss'; }
    public function label(): string { return 'CLSS (Honeywell)'; }
    public function description(): string { return 'Lee del tablero CLSS el avance de pruebas y las señales de los paneles de incendio para el Reporte CLSS.'; }

    public function configSchema(): array
    {
        return [
            ['key' => 'url',   'label' => 'URL del tablero CLSS', 'type' => 'url',      'required' => true, 'secret' => false, 'placeholder' => 'https://clss.siccob.com.mx', 'help' => 'La dirección del tablero, sin / al final.'],
            ['key' => 'token', 'label' => 'Llave de lectura',     'type' => 'password', 'required' => true, 'secret' => true,  'help' => 'Empieza con «clss_». La genera el administrador del tablero; solo permite leer.'],
        ];
    }

    /** Ninguno: es de solo lectura. Va un nombre que no existe porque una lista vacía significa «todos». */
    public function subscribedEvents(): array
    {
        return ['clss.solo-lectura'];
    }

    public function handleEvent(string $eventType, array $data, Integration $config): array
    {
        return ['status' => 'skipped', 'note' => 'CLSS es de solo lectura.'];
    }

    public function query(string $operation, array $params, Integration $config): array
    {
        if (! in_array($operation, self::OPERACIONES, true)) {
            return parent::query($operation, $params, $config);
        }
        try {
            $r = Http::withToken((string) $config->conf('token'))
                ->acceptJson()
                ->timeout(45)
                ->get(rtrim((string) $config->conf('url'), '/') . '/api/v1/' . $operation, $params);
            if ($r->successful()) {
                return ['available' => true, 'data' => $r->json()];
            }
            return [
                'available' => false,
                'reason'    => $r->status() === 401 ? 'unauthorized' : 'error',
                'message'   => $r->json('error') ?? "El tablero respondió {$r->status()}.",
            ];
        } catch (\Throwable $e) {
            return ['available' => false, 'reason' => 'error', 'message' => $e->getMessage()];
        }
    }

    public function testConnection(Integration $config): array
    {
        if (! $this->isConfigured($config)) {
            return ['ok' => false, 'message' => 'Faltan la URL o la llave.'];
        }
        $r = $this->query('estado', ['periodo' => 'todo'], $config);
        if (! $r['available']) {
            return ['ok' => false, 'message' => $r['message'] ?? 'No se pudo conectar con el tablero.'];
        }
        $sitios = collect($r['data']['sitios'] ?? []);
        $nombres = $sitios->pluck('nombre')->take(5)->implode(', ');
        return ['ok' => true, 'message' => "Conectado a {$r['data']['inquilino']}: {$sitios->count()} sitio(s) con datos de CLSS"
            . ($nombres ? " ({$nombres})." : '.')];
    }

    public function supportedActions(): array
    {
        return [
            ['key' => 'ping', 'label' => 'Probar conexión', 'description' => 'Verifica la llave y lista los sitios con datos de CLSS.', 'params' => []],
        ];
    }

    public function runAction(string $action, array $params, Integration $config): array
    {
        if ($action === 'ping') {
            return $this->testConnection($config);
        }
        return parent::runAction($action, $params, $config);
    }
}
