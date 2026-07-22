<?php

namespace App\Support\Integrations;

use App\Models\Integration;

/**
 * Contrato de un conector a un sistema externo (Odoo, Jira SM, …).
 *
 * REGLA DE ORO: ningún método debe lanzar hacia la petición del negocio. La salida corre
 * en un job encolado y aislado; las consultas devuelven un resultado "degradado" cuando la
 * integración está apagada o mal configurada. Si el sistema externo no existe, la app sigue.
 */
interface IntegrationProvider
{
    /** Clave estable del proveedor: 'odoo' | 'jira'. */
    public function key(): string;

    /** Nombre legible para la UI. */
    public function label(): string;

    /** Descripción corta de para qué sirve. */
    public function description(): string;

    /**
     * Campos de configuración que la UI debe pedir.
     *
     * @return array<int,array{key:string,label:string,type:string,required:bool,secret:bool,placeholder?:string,help?:string}>
     */
    public function configSchema(): array;

    /** ¿Tiene lo mínimo para operar? (no prueba la conexión, solo valida presencia). */
    public function isConfigured(Integration $config): bool;

    /**
     * Empuja un evento de negocio hacia el sistema externo (SALIDA). Corre dentro del job.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>  instantánea de la respuesta para la bitácora
     */
    public function handleEvent(string $eventType, array $data, Integration $config): array;

    /**
     * Procesa una llamada ENTRANTE ya verificada (sync de vuelta desde el externo).
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function handleInbound(array $payload, Integration $config): array;

    /**
     * Consulta síncrona hacia el externo (p. ej. inventario/almacén en Odoo). DEBE degradar
     * con gracia: si está apagado o falla, devuelve un resultado marcado como no disponible.
     *
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    public function query(string $operation, array $params, Integration $config): array;

    /**
     * Prueba de conexión para la UI.
     *
     * @return array{ok:bool,message:string}
     */
    public function testConnection(Integration $config): array;

    /**
     * Tipos de evento de negocio a los que reacciona (para documentación/filtrado).
     * Vacío = le interesan todos.
     *
     * @return array<int,string>
     */
    public function subscribedEvents(): array;
}
