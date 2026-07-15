<?php

namespace App\Services\Ai\Tools\Contracts;

use App\Models\User;

/**
 * Una herramienta que el asistente de IA puede invocar. Cada herramienta se
 * define UNA vez y se reutiliza tanto por el chat interno (agent loop) como por
 * el futuro servidor MCP.
 *
 * REGLA DE ORO: `handle()` corre SIEMPRE como el usuario autenticado que usa el
 * chat, apoyándose en los controladores/servicios existentes. Así la IA hereda
 * los permisos Spatie y el alcance de datos del usuario — no puede hacer nada
 * que el usuario no pueda.
 */
interface Tool
{
    /** Nombre único (snake_case) con el que el modelo la invoca. */
    public function name(): string;

    /** Descripción para el modelo: qué hace y CUÁNDO usarla. */
    public function description(): string;

    /** Esquema JSON de los parámetros (objeto JSON Schema). */
    public function parameters(): array;

    /**
     * ¿Modifica datos? Las de lectura son false. Las de escritura (crear/editar)
     * son true → se auditan.
     */
    public function mutating(): bool;

    /**
     * ¿Requiere confirmación humana antes de ejecutarse? Las acciones sensibles
     * (identidad, seguridad, borrados) devuelven true → el agente se detiene y le
     * pide al usuario que confirme antes de ejecutar.
     */
    public function confirm(): bool;

    /**
     * Ejecuta la herramienta como `$user`. Devuelve un arreglo serializable a
     * JSON que se le entrega de vuelta al modelo. Ante error de permiso/negocio,
     * devuelve `['error' => '...']` en vez de lanzar (el modelo lo relata).
     */
    public function handle(array $args, User $user): array;
}
