<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Captación conversacional de eventos (WhatsApp/Telegram).
 * - channels: líneas de mensajería, cada una dedicada a un cliente.
 * - capture_contacts / capture_conversations / capture_messages: el hilo de
 *   captación por remitente hasta generar el evento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('provider', 20)->default('telegram');   // telegram | whatsapp
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->text('access_token')->nullable();               // cifrado (bot token / WA token)
            $table->string('phone_number_id')->nullable();          // WhatsApp
            $table->foreignId('default_event_type_id')->nullable()->constrained('event_types')->nullOnDelete();
            $table->foreignId('default_system_id')->nullable()->constrained('catalogs')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete(); // atribución del evento
            $table->string('agent_name')->default('Asistente');
            $table->text('instructions')->nullable();
            $table->boolean('ai_enabled')->default(true);
            $table->boolean('is_active')->default(true);
            $table->jsonb('metadata')->nullable();                  // tg_bot, tg_webhook_secret, etc.
            $table->timestamps();
        });

        Schema::create('capture_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->string('external_id');                          // chat_id (telegram) / wa_id (whatsapp)
            $table->string('name')->nullable();
            $table->timestamps();
            $table->unique(['channel_id', 'external_id']);
        });

        Schema::create('capture_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('capture_contacts')->cascadeOnDelete();
            $table->string('status', 20)->default('open');          // open | closed
            $table->jsonb('state')->nullable();                     // params acumulados
            $table->foreignId('event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->index(['channel_id', 'status']);
        });

        Schema::create('capture_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('capture_conversations')->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->string('direction', 8);                         // in | out
            $table->string('external_message_id')->nullable();      // idempotencia del proveedor
            $table->text('body')->nullable();
            $table->jsonb('payload')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['channel_id', 'external_message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capture_messages');
        Schema::dropIfExists('capture_conversations');
        Schema::dropIfExists('capture_contacts');
        Schema::dropIfExists('channels');
    }
};
