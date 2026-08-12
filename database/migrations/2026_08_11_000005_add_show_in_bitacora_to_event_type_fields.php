<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca por campo si se imprime en la Bitácora de eventos (espejo de
 * activity_type_fields.show_in_bitacora). Por defecto apagado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_type_fields', function (Blueprint $table) {
            $table->boolean('show_in_bitacora')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('event_type_fields', function (Blueprint $table) {
            $table->dropColumn('show_in_bitacora');
        });
    }
};
