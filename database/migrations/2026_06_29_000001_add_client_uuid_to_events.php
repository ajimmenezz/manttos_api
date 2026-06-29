<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Llave de idempotencia generada por el cliente (app móvil) al crear un evento.
 * Si la app sube un evento, el servidor lo crea, pero la app no alcanza a borrar
 * el pendiente (se mata o pierde la red), el siguiente intento reenvía el mismo
 * `client_uuid` → el backend devuelve el evento existente en vez de duplicarlo.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('client_uuid', 64)->nullable()->unique()->after('folio');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropUnique(['client_uuid']);
            $table->dropColumn('client_uuid');
        });
    }
};
