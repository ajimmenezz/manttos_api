<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automatizaciones a nivel EVENTO (server-side): al crear/documentar/mover/asignar/comentar
 * un evento, si las condiciones (sobre el formulario, el directorio del dispositivo y los
 * atributos del evento) se cumplen, ejecuta una acción — integración (Odoo/Jira), consulta
 * que rellena el evento, acción interna o generación de un evento de seguimiento.
 *
 * Análogo a activity_type_automations, pero evaluado y ejecutado en el servidor porque las
 * integraciones corren en jobs y no pueden depender de un cliente abierto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_type_automations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_type_id')->constrained('event_types')->cascadeOnDelete();
            $table->foreignId('system_id')->constrained('catalogs')->cascadeOnDelete();
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            // Momento del ciclo de vida que dispara: created | documented | status_changed | assigned | comment_added
            $table->string('event', 30);
            // Filtro opcional del estado destino (solo para event='status_changed').
            $table->string('status_key')->nullable();

            // Condiciones (RuleGroup jsonb). null = siempre que ocurra el evento.
            $table->jsonb('trigger')->nullable();

            // Tipo de acción: integration | query | internal | event
            $table->string('action_kind', 20);

            // integration / query
            $table->string('provider', 20)->nullable();       // odoo | jira
            $table->string('action', 60)->nullable();         // clave de supportedActions()
            $table->jsonb('params_map')->nullable();          // [{param_key, mode:'constant'|'field', value?, source?, source_field_key?}]
            $table->string('result_target')->nullable();      // query: 'comment' | clave de campo

            // internal
            $table->string('internal_action', 20)->nullable(); // change_status | assign | comment | notify
            $table->jsonb('internal_config')->nullable();      // {status_key?, assignee_id?, template?, ...}

            // event (seguimiento)
            $table->foreignId('target_event_type_id')->nullable()->constrained('event_types')->nullOnDelete();
            $table->jsonb('prefill')->nullable();              // [{target_field_key, mode:'constant'|'copy', value?, source?, source_field_key?}]

            // Ejecuta una sola vez por evento (evita re-disparar en cada re-guardado).
            $table->boolean('run_once')->default(true);

            $table->timestamps();
            $table->index(['event_type_id', 'system_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_type_automations');
    }
};
