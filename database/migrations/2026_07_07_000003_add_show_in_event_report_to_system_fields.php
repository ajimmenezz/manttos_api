<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Marca un campo del directorio (dispositivo) para explotarlo como KPI en el
        // Reporte de eventos, sobre el dispositivo ligado a cada evento. Los FILTROS del
        // reporte incluyen TODOS los campos del directorio (no dependen de esta bandera).
        Schema::table('system_fields', function (Blueprint $table) {
            $table->boolean('show_in_event_report')->default(false)->after('show_in_bitacora');
        });
    }

    public function down(): void
    {
        Schema::table('system_fields', function (Blueprint $table) {
            $table->dropColumn('show_in_event_report');
        });
    }
};
