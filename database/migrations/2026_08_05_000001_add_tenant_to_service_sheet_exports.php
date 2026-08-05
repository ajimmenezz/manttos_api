<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guarda el tenant (dominio white-label) que solicitó la exportación, para que el Job
 * —que corre en la cola sin request— use el branding correcto en la hoja de servicio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_sheet_exports', function (Blueprint $table) {
            $table->string('tenant')->nullable()->after('requested_by');
        });
    }

    public function down(): void
    {
        Schema::table('service_sheet_exports', function (Blueprint $table) {
            $table->dropColumn('tenant');
        });
    }
};
