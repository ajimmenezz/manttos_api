<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mapeo de LÍNEAS para las acciones de integración que arman un documento con renglones
 * (p. ej. una cotización de Odoo con productos). Cada línea mapea producto/cantidad/precio/
 * descripción desde un campo del evento o un valor fijo. Vive aparte de `params_map` (que es
 * plano) porque las líneas son una estructura repetible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_type_automations', function (Blueprint $table) {
            $table->jsonb('lines_map')->nullable()->after('params_map');
        });
    }

    public function down(): void
    {
        Schema::table('event_type_automations', function (Blueprint $table) {
            $table->dropColumn('lines_map');
        });
    }
};
