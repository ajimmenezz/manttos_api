<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catálogo GENERAL de estados de evento (ITIL: configurable).
        Schema::create('event_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();        // estable, p/ lógica (ej. 'en_progreso')
            $table->string('label', 80);
            $table->string('color', 9)->default('#64748B');
            $table->string('category', 20)->default('abierto'); // abierto | resuelto | cerrado (reportería)
            $table->boolean('is_initial')->default(false);      // estado válido al crear
            $table->boolean('is_terminal')->default(false);     // estado final (sin transiciones de salida)
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Transiciones GENERALES permitidas (flujo por defecto).
        Schema::create('event_status_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_status_id')->constrained('event_statuses')->cascadeOnDelete();
            $table->foreignId('to_status_id')->constrained('event_statuses')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['from_status_id', 'to_status_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_status_transitions');
        Schema::dropIfExists('event_statuses');
    }
};
