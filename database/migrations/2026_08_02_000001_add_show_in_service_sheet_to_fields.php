<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bandera "mostrar en la hoja de servicio" por campo, análoga a show_in_report (KPIs) y
 * show_in_event_report (directorio). Default TRUE = se conserva el comportamiento actual
 * (la hoja mostraba todos los campos).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_type_fields', function (Blueprint $table) {
            $table->boolean('show_in_service_sheet')->default(true)->after('is_active');
        });
        Schema::table('system_fields', function (Blueprint $table) {
            $table->boolean('show_in_service_sheet')->default(true)->after('show_in_event_report');
        });
    }

    public function down(): void
    {
        Schema::table('event_type_fields', fn (Blueprint $t) => $t->dropColumn('show_in_service_sheet'));
        Schema::table('system_fields', fn (Blueprint $t) => $t->dropColumn('show_in_service_sheet'));
    }
};
