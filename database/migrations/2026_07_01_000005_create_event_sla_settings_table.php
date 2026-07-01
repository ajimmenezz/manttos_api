<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Configuración de SLA de eventos. Una fila con client_id NULL = default GLOBAL;
        // una fila por cliente = override de ese cliente/contrato. Todo el detalle vive en
        // JSON porque se resuelve una config por evento (no se consulta en agregado):
        //  - matrix:     { "alto|alta": "critica", ... }  (impacto|urgencia → prioridad)
        //  - priorities: { "critica": { scheduled:false, targets:{ remota_n1:2, en_sitio:4, ... } }, ... }
        //  - calendar:   { mode:"business"|"24_7", work_days:[1..5], start:"08:00", end:"18:00",
        //                  timezone:"America/Mexico_City", holidays:["2026-12-25", ...] }
        Schema::create('event_sla_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->unique()   // NULL = global default
                ->constrained('clients')->cascadeOnDelete();
            $table->boolean('enabled')->default(true);             // medir SLA en este alcance
            $table->json('matrix');
            $table->json('priorities');
            $table->json('calendar');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_sla_settings');
    }
};
