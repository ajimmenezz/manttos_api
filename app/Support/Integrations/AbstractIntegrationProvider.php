<?php

namespace App\Support\Integrations;

use App\Models\Integration;

/**
 * Base común para los proveedores: implementa lo genérico (validación de config a partir
 * del esquema, defaults seguros para entrada/consulta) para que cada conector solo escriba
 * lo suyo. Todos los defaults son NO-OP a prueba de fallos.
 */
abstract class AbstractIntegrationProvider implements IntegrationProvider
{
    public function isConfigured(Integration $config): bool
    {
        foreach ($this->configSchema() as $field) {
            if (($field['required'] ?? false) && blank($config->conf($field['key']))) {
                return false;
            }
        }
        return true;
    }

    /** Por defecto no reacciona a entradas (los conectores bidireccionales lo sobreescriben). */
    public function handleInbound(array $payload, Integration $config): array
    {
        return ['status' => 'ignored', 'note' => 'Este proveedor aún no procesa entradas.'];
    }

    /** Por defecto una consulta desconocida degrada a "no disponible". */
    public function query(string $operation, array $params, Integration $config): array
    {
        return ['available' => false, 'reason' => 'not_implemented', 'operation' => $operation];
    }

    public function subscribedEvents(): array
    {
        return []; // todos
    }

    public function supportedActions(): array
    {
        return [];
    }

    public function runAction(string $action, array $params, Integration $config): array
    {
        return ['ok' => false, 'message' => 'Este proveedor no soporta acciones manuales todavía.'];
    }
}
