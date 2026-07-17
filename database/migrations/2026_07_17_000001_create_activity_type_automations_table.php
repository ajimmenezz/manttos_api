<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automatizaciones a nivel ACTIVIDAD: al documentar una actividad de un tipo, si sus
 * condiciones de disparo (sobre campos del formulario y/o del directorio) se cumplen,
 * se genera otra actividad (mismo dispositivo) o un evento, con posibilidad de
 * pre-llenar campos del destino (valor fijo o copiado del origen). Scoped por
 * (activity_type_id, system_id) igual que los campos del tipo de actividad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_type_automations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_type_id')->constrained('catalogs')->cascadeOnDelete();
            $table->foreignId('system_id')->constrained('catalogs')->cascadeOnDelete();
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            // Árbol de condiciones (RuleGroup Y/O sobre form + device); null = siempre dispara.
            $table->jsonb('trigger')->nullable();

            // Acción: generar 'activity' (mismo dispositivo) o 'event'.
            $table->string('action_type', 20);
            $table->foreignId('target_activity_type_id')->nullable()->constrained('catalogs')->nullOnDelete();
            $table->foreignId('target_event_type_id')->nullable()->constrained('event_types')->nullOnDelete();

            // Prellenado del destino: [{ target_field_key, mode:'constant'|'copy',
            //   value?, source?:'form'|'device', source_field_key? }]
            $table->jsonb('prefill')->nullable();

            $table->timestamps();

            $table->index(['activity_type_id', 'system_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_type_automations');
    }
};
