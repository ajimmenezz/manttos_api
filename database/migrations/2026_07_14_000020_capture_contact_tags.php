<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Etiquetado de contactos de captación a un cliente/sitio, para que el agente no
 * tenga que preguntar de dónde escribe la persona. Permite además pre-registrar
 * contactos desde la ficha del cliente/sitio (por teléfono en WhatsApp o usuario
 * en Telegram) y adoptarlos al primer mensaje.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('capture_contacts', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('name')->constrained('clients')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->after('client_id')->constrained('sites')->nullOnDelete();
            $table->string('username')->nullable()->after('site_id');           // handle de Telegram (sin @, minúsculas)
            $table->boolean('pre_registered')->default(false)->after('username'); // dado de alta antes de escribir
            $table->index(['channel_id', 'username']);
        });

        // external_id opcional: un pre-registro de Telegram aún no conoce el chat_id.
        DB::statement('ALTER TABLE capture_contacts ALTER COLUMN external_id DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE capture_contacts ALTER COLUMN external_id SET NOT NULL');

        Schema::table('capture_contacts', function (Blueprint $table) {
            $table->dropIndex(['channel_id', 'username']);
            $table->dropConstrainedForeignId('client_id');
            $table->dropConstrainedForeignId('site_id');
            $table->dropColumn(['username', 'pre_registered']);
        });
    }
};
