<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catálogo de tipos de evento (dedicado: lleva naturaleza ITIL + prioridad por defecto).
        Schema::create('event_types', function (Blueprint $table) {
            $table->id();
            $table->string('label', 100);
            $table->string('nature', 20)->default('incidente'); // incidente | solicitud (extensible: problema | cambio)
            $table->string('color', 9)->default('#3B5BDB');
            $table->string('default_priority', 10)->default('media'); // baja | media | alta | critica
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Pivot tipo de evento ↔ sistema (qué tipos aplican a qué sistema). Igual a activity_type_systems.
        Schema::create('event_type_systems', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_type_id')->constrained('event_types')->cascadeOnDelete();
            $table->foreignId('system_id')->constrained('catalogs')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['event_type_id', 'system_id']);
        });

        // Override de transiciones POR TIPO: si un tipo define filas aquí, manda sobre las generales.
        Schema::create('event_type_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_type_id')->constrained('event_types')->cascadeOnDelete();
            $table->foreignId('from_status_id')->constrained('event_statuses')->cascadeOnDelete();
            $table->foreignId('to_status_id')->constrained('event_statuses')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['event_type_id', 'from_status_id', 'to_status_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_type_transitions');
        Schema::dropIfExists('event_type_systems');
        Schema::dropIfExists('event_types');
    }
};
