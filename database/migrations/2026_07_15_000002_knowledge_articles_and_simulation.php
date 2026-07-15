<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * (1) Base de conocimiento REAL: guarda el cuerpo legible del artículo (`body_md`)
 *     en cada documento de soporte, para navegarlo/leerlo como una KB tipo ITSM
 *     (no solo como fragmentos invisibles del RAG). Backfill de los documentos ya
 *     ingeridos a partir de sus fragmentos.
 * (2) Simulador del agente: marca las conversaciones de captación creadas por el
 *     tester para no mezclarlas con la Bandeja real.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_documents', function (Blueprint $table) {
            $table->longText('body_md')->nullable()->after('audience'); // artículo legible (markdown)
        });

        Schema::table('capture_conversations', function (Blueprint $table) {
            $table->boolean('is_simulation')->default(false)->index()->after('handling');
        });

        // Backfill: reconstruye el cuerpo de los documentos de soporte ya ingeridos
        // concatenando sus fragmentos en orden. Los nuevos guardarán su markdown al ingerir.
        $docs = DB::table('ai_documents')->where('collection', 'support')->pluck('id');
        foreach ($docs as $id) {
            $body = DB::table('ai_document_chunks')->where('ai_document_id', $id)
                ->orderBy('idx')->pluck('content')->implode("\n\n");
            if (trim($body) !== '') {
                DB::table('ai_documents')->where('id', $id)->update(['body_md' => $body]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('capture_conversations', function (Blueprint $table) {
            $table->dropColumn('is_simulation');
        });
        Schema::table('ai_documents', function (Blueprint $table) {
            $table->dropColumn('body_md');
        });
    }
};
