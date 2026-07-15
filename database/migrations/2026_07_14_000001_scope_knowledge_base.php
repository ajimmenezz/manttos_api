<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extiende la base de conocimiento del asistente (ai_documents/ai_document_chunks)
 * para servir además el SOPORTE DE 1er NIVEL del agente de captación:
 *
 *  - `collection`: separa los dos corpus que comparten tabla:
 *      · 'assistant' → manual/guías del asistente interno (lo que ya existía).
 *      · 'support'   → base de conocimiento por SISTEMA para dar soporte al cliente.
 *  - `catalog_id` (sistema) + `client_id` (null = todos): alcance del artículo.
 *  - `audience`: 'support' (el agente puede guiar al usuario con esto) vs
 *      'internal' (solo moldea el tono/criterio de respuesta; nunca se cita textual).
 *  - archivo original + estado de ingesta (para subir PDF/Word y ver el avance).
 *
 * El alcance se DENORMALIZA en los fragmentos para poder filtrar en SQL antes de
 * calcular la similitud coseno en PHP (recorrido acotado, no todo el corpus).
 *
 * Además agrega `first_level_support` a las líneas de captación: por línea se
 * decide off / assist (siempre crea el ticket + tips) / deflect (resolver primero).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_documents', function (Blueprint $table) {
            $table->string('collection', 20)->default('assistant')->index();
            $table->foreignId('catalog_id')->nullable()->constrained('catalogs')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('audience', 20)->default('support');
            $table->string('original_filename')->nullable();
            $table->string('file_path')->nullable();
            $table->string('status', 20)->default('ready'); // pending | processing | ready | failed
            $table->text('error')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('structured')->default(false); // pasó por el modelo de estructuración
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('ai_document_chunks', function (Blueprint $table) {
            $table->string('collection', 20)->default('assistant');
            $table->unsignedBigInteger('catalog_id')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->string('audience', 20)->default('support');

            $table->index(['collection', 'catalog_id']);
            $table->index(['collection', 'client_id']);
        });

        // Los documentos/fragmentos ya existentes son del asistente interno.
        DB::table('ai_documents')->update(['collection' => 'assistant', 'audience' => 'internal', 'status' => 'ready']);
        DB::table('ai_document_chunks')->update(['collection' => 'assistant', 'audience' => 'internal']);

        Schema::table('channels', function (Blueprint $table) {
            // off = solo capta (como hoy); assist = crea ticket + da tips del manual;
            // deflect = intenta resolver con el manual primero y escala si no funciona.
            $table->string('first_level_support', 20)->default('off');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn('first_level_support');
        });

        Schema::table('ai_document_chunks', function (Blueprint $table) {
            $table->dropIndex(['collection', 'catalog_id']);
            $table->dropIndex(['collection', 'client_id']);
            $table->dropColumn(['collection', 'catalog_id', 'client_id', 'audience']);
        });

        Schema::table('ai_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('catalog_id');
            $table->dropConstrainedForeignId('client_id');
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn([
                'collection', 'audience', 'original_filename', 'file_path',
                'status', 'error', 'is_active', 'structured',
            ]);
        });
    }
};
