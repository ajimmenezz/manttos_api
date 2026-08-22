<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dónde va la línea de firma a lo ancho de la hoja ('left'|'center'|'right').
 *
 * Viaja en la solicitud —y no como parámetro del Job— porque el ZIP lo arma un proceso
 * en cola, sin request de dónde leerlo, igual que `signature` y `tenant`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_sheet_exports', function (Blueprint $table) {
            $table->string('signature_align', 10)->nullable()->after('signature');
        });
    }

    public function down(): void
    {
        Schema::table('service_sheet_exports', function (Blueprint $table) {
            $table->dropColumn('signature_align');
        });
    }
};
