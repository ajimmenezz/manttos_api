<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo de comunicación interna (chat tipo WhatsApp) — Fase 1: núcleo.
 *
 * OJO con los nombres: ya existen `capture_conversations`/`capture_messages` (captación
 * por WhatsApp/Telegram). Estas tablas son OTRA cosa: conversación entre USUARIOS de la
 * plataforma, por eso van sin prefijo (`conversations`, `messages`, ...) tal como el spec.
 *
 * Decisiones respecto al spec (sección 4.1):
 *  - `client_id` en `conversations` fija el ALCANCE: null = conversación puramente interna;
 *    si participa un usuario de cliente, se graba su cliente y ya nadie de otro cliente entra.
 *  - `direct_key` (columna nueva, no estaba en el spec): llave canónica "menor-mayor" de los
 *    dos user_id de una conversación directa, con índice único. Evita duplicar el 1-a-1 en
 *    condiciones de carrera (dos usuarios abriendo el chat a la vez) sin tener que hacer un
 *    subquery de participantes con lock. En grupos es null (Postgres permite N nulls en unique).
 *  - `last_message_at` se mantiene denormalizado para ordenar la lista sin joins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10);                 // 'direct' | 'group'
            $table->string('name', 150)->nullable();    // null en direct (se arma con el otro participante)
            $table->string('avatar_url', 1000)->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            // Alcance multi-cliente: null = interna. Ver App\Support\ChatScope.
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('direct_key', 40)->nullable(); // "menorId:mayorId" solo en direct
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('direct_key');
            $table->index(['type', 'last_message_at']);
            $table->index('client_id');
        });

        Schema::create('conversation_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 10)->default('member');   // 'member' | 'admin' (admin del grupo)
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();        // null = participante ACTIVO
            // Sin FK a messages: la tabla se crea después y el mensaje puede borrarse (soft delete),
            // basta el id como marca de agua para calcular no-leídos.
            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->timestamp('muted_until')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
            // Lista de conversaciones del usuario: filtra por user_id + activos.
            $table->index(['user_id', 'left_at']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->text('body')->nullable();               // null si el mensaje es solo adjuntos
            $table->foreignId('reply_to_id')->nullable()->constrained('messages')->nullOnDelete();
            // Idempotencia de la cola offline del móvil (mismo patrón que events.client_uuid).
            $table->string('client_uuid', 64)->nullable()->unique();
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();                          // soft delete = "mensaje eliminado"

            // Paginado por cursor hacia atrás: (conversation_id, created_at desc, id desc).
            $table->index(['conversation_id', 'created_at']);
            $table->index(['conversation_id', 'id']);
        });

        Schema::create('message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->string('url', 1000);                    // ya subida por POST /media/upload
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('kind', 10)->default('image');   // 'image' | 'file' | 'video' (video = fase 4)
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('thumb_url', 1000)->nullable();
            $table->timestamps();

            $table->index('message_id');
        });

        Schema::create('message_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['message_id', 'user_id']);
            $table->index(['user_id', 'read_at']);
        });

        Schema::create('conversation_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            // Polimórfico "a mano" (no morphs) porque los tipos son un enum corto y controlado.
            $table->string('linkable_type', 20);            // 'event' | 'maintenance' | 'site'
            $table->unsignedBigInteger('linkable_id');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            // Una conversación no se liga dos veces al mismo objeto.
            $table->unique(['conversation_id', 'linkable_type', 'linkable_id'], 'conversation_links_unique');
            $table->index(['linkable_type', 'linkable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_links');
        Schema::dropIfExists('message_reads');
        Schema::dropIfExists('message_attachments');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversation_participants');
        Schema::dropIfExists('conversations');
    }
};
