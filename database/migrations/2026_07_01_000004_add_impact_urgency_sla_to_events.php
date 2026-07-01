<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // Impacto × Urgencia → Prioridad (matriz configurable). Nullable para eventos
            // previos a esta funcionalidad; la prioridad sigue existiendo (derivada u override).
            $table->string('impact', 10)->nullable()->after('priority');   // alto | medio | bajo
            $table->string('urgency', 10)->nullable()->after('impact');    // alta | media | baja
            // true = la prioridad se calculó de la matriz; false = el usuario la fijó a mano.
            $table->boolean('priority_auto')->default(true)->after('urgency');
            // Cuando la prioridad cae en una celda "se programa" (sin reloj de SLA),
            // aquí va la fecha/hora en que se programó la atención.
            $table->timestamp('scheduled_attention_at')->nullable()->after('priority_auto');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['impact', 'urgency', 'priority_auto', 'scheduled_attention_at']);
        });
    }
};
