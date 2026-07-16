<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guarda la evaluación de la IA sobre una regla del agente (puntaje 0-100 +
 * fortalezas/problemas/sugerencias), para mostrar la calidad en el editor y en el
 * listado. Es orientativa: nunca bloquea guardar la regla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('capture_agent_rules', function (Blueprint $table) {
            $table->unsignedTinyInteger('ai_score')->nullable()->after('sort_order');
            $table->json('ai_review')->nullable()->after('ai_score');
        });
    }

    public function down(): void
    {
        Schema::table('capture_agent_rules', function (Blueprint $table) {
            $table->dropColumn(['ai_score', 'ai_review']);
        });
    }
};
