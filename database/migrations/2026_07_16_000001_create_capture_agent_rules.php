<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reglas de comportamiento del agente de captación que un superadmin agrega desde
 * la plataforma para ir corrigiendo/afinando las respuestas (lo que hoy se hace
 * editando el prompt en código). Se inyectan en el system prompt del CaptureAgent
 * (bandeja real + simulador). Alcance: global / por línea / por sistema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capture_agent_rules', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 16)->default('global'); // global | channel | system
            $table->foreignId('channel_id')->nullable()->constrained('channels')->nullOnDelete();
            $table->foreignId('catalog_id')->nullable()->constrained('catalogs')->nullOnDelete(); // sistema
            $table->string('title', 160)->nullable();
            $table->text('instruction');
            $table->text('example_bad')->nullable();
            $table->text('example_good')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            // Auditoría + trazabilidad (regla nacida de corregir una respuesta concreta).
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('source_conversation_id')->nullable()->constrained('capture_conversations')->nullOnDelete();
            $table->json('source_context')->nullable(); // {inbound, reply} de la respuesta corregida
            $table->timestamps();

            $table->index(['scope', 'is_active']);
            $table->index(['channel_id', 'is_active']);
            $table->index(['catalog_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capture_agent_rules');
    }
};
