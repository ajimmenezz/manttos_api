<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Vaciar conversación" POR USUARIO (como WhatsApp): marca de agua personal. Al vaciar,
 * se fija al último mensaje; a partir de ahí ese usuario solo ve lo NUEVO. No borra nada
 * para los demás (es visual, por participante). Sin FK, igual que last_read_message_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->unsignedBigInteger('cleared_before_message_id')->nullable()->after('last_read_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->dropColumn('cleared_before_message_id');
        });
    }
};
