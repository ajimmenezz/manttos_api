<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El ZIP de hojas de servicio pasa a generarse por SITIO (no por cliente). Se conserva
 * client_id (derivado del sitio) para el alcance y el nombrado; nullable para exports
 * viejos por cliente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_sheet_exports', function (Blueprint $table) {
            $table->foreignId('site_id')->nullable()->after('client_id')->constrained('sites')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('service_sheet_exports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('site_id');
        });
    }
};
