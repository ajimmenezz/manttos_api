<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dónde va la línea de "Nombre y Firma de Conformidad" en las hojas del ZIP:
 * 'end' (una al final de cada hoja), 'page' (una por página) o null (ninguna).
 * Se guarda en la solicitud porque el ZIP lo arma un Job, sin request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_sheet_exports', function (Blueprint $table) {
            $table->string('signature', 10)->nullable()->after('tenant');
        });
    }

    public function down(): void
    {
        Schema::table('service_sheet_exports', function (Blueprint $table) {
            $table->dropColumn('signature');
        });
    }
};
