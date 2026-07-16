<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Resumen de IA" del evento: síntesis de TODO el evento (formulario, notas,
 * estados, comentarios, diagnóstico) generada por la IA. No se regenera en cada
 * guardado (sería lento y caro): cada modificación lo marca DESACTUALIZADO
 * (ai_summary_stale) y se regenera al usarse (detalle web / agente de captación).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->text('ai_summary')->nullable()->after('ai_diagnosis_at');
            $table->timestamp('ai_summary_at')->nullable()->after('ai_summary');
            $table->boolean('ai_summary_stale')->default(true)->after('ai_summary_at');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['ai_summary', 'ai_summary_at', 'ai_summary_stale']);
        });
    }
};
