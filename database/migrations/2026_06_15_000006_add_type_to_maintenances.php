<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            // 'normal' (lista de dispositivos + dashboard de cobertura) | 'contrato'
            // (exige fechas y define frecuencias de actividad por tipo de dispositivo).
            $table->string('type', 20)->default('normal')->after('catalog_id');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
