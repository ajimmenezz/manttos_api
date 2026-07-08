<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Marca un campo del formulario de eventos para explotarlo como KPI + filtro
        // en el Reporte de eventos. Análogo a system_fields.show_in_dashboard.
        Schema::table('event_type_fields', function (Blueprint $table) {
            $table->boolean('show_in_report')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('event_type_fields', function (Blueprint $table) {
            $table->dropColumn('show_in_report');
        });
    }
};
