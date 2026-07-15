<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identidad de mensajería en el usuario (para el modelo ITSM de autoservicio):
 * - users.telegram_username / users.whatsapp_number (opcionales): el propio usuario
 *   o un administrador los asocia; el bot reconoce a QUIÉN escribe y respeta su alcance.
 * - capture_contacts.user_id: vincula el contacto de mensajería con el usuario reconocido.
 * - channels.require_registered: la línea solo atiende a personas registradas/reconocidas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('telegram_username')->nullable()->unique()->after('email');
            $table->string('whatsapp_number')->nullable()->unique()->after('telegram_username');
        });

        Schema::table('capture_contacts', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('site_id')->constrained('users')->nullOnDelete();
        });

        Schema::table('channels', function (Blueprint $table) {
            $table->boolean('require_registered')->default(false)->after('ai_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn('require_registered');
        });

        Schema::table('capture_contacts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['telegram_username']);
            $table->dropUnique(['whatsapp_number']);
            $table->dropColumn(['telegram_username', 'whatsapp_number']);
        });
    }
};
