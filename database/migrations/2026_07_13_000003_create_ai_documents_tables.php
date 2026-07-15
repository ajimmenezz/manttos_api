<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Base de conocimiento del asistente (RAG). Guarda documentos (manuales/guías)
 * troceados en fragmentos con su embedding. No usa pgvector: para un corpus de
 * manual (cientos de fragmentos) la similitud coseno se calcula en PHP. Si el
 * corpus creciera mucho, se migra a pgvector sin cambiar la interfaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_documents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('source')->index();     // ruta/origen (para re-ingesta idempotente)
            $table->string('kind', 40)->default('manual'); // manual | guia | otro
            $table->unsignedInteger('chunks_count')->default(0);
            $table->timestamps();
        });

        Schema::create('ai_document_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_document_id')->constrained('ai_documents')->cascadeOnDelete();
            $table->unsignedInteger('idx')->default(0);      // orden dentro del documento
            $table->string('heading')->nullable();           // sección/título del fragmento
            $table->text('content');
            $table->jsonb('embedding');                       // arreglo de floats (dim del modelo)
            $table->string('embedding_model', 60)->nullable();
            $table->timestamps();

            $table->index('ai_document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_document_chunks');
        Schema::dropIfExists('ai_documents');
    }
};
