<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Origen del consumo de IA para poder ver el gasto por área:
 *   assistant = asistente interno (chat con herramientas)
 *   captacion = agente de captación (WhatsApp/Telegram/app)
 *   ingest    = estructuración de manuales de la base de conocimiento
 * Los registros previos quedan como 'assistant' (era lo único que se registraba).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_interactions', function (Blueprint $table) {
            $table->string('source', 20)->default('assistant')->index()->after('conversation_id');
        });
    }

    public function down(): void
    {
        Schema::table('ai_interactions', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
