<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bandeja de captación: hilo persistente por contacto + relevo humano + memoria.
 *
 * - Las conversaciones dejan de cerrarse al crear el evento (se vuelven hilos
 *   persistentes por contacto); un mismo hilo puede generar varios eventos, cada
 *   uno marcado con un checkpoint (mensaje direction='system').
 * - `handling` (ai|human) controla si responde el agente o un humano tomó la
 *   conversación; `assigned_agent_id` es quién la atiende.
 * - `context_summary` = memoria acumulada del agente para no re-preguntar.
 * - `unread_count`/`last_inbound_at` alimentan la bandeja (no leídos + orden).
 * - `capture_messages.sender_user_id` = autor humano de un mensaje saliente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('capture_conversations', function (Blueprint $table) {
            $table->string('handling', 10)->default('ai')->after('status');   // ai | human
            $table->foreignId('assigned_agent_id')->nullable()->after('handling')
                ->constrained('users')->nullOnDelete();
            $table->text('context_summary')->nullable()->after('state');       // memoria del agente
            $table->unsignedInteger('unread_count')->default(0)->after('context_summary');
            $table->timestamp('last_inbound_at')->nullable()->after('last_message_at');
        });

        Schema::table('capture_messages', function (Blueprint $table) {
            $table->foreignId('sender_user_id')->nullable()->after('direction')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('capture_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sender_user_id');
        });

        Schema::table('capture_conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_agent_id');
            $table->dropColumn(['handling', 'context_summary', 'unread_count', 'last_inbound_at']);
        });
    }
};
