<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_type_fields', function (Blueprint $table) {
            // Opciones específicas por tipo de campo (evita columnas nuevas por cada
            // opción). Ej.: number → {min, max, allowNegative, decimals, unit};
            // time → {granularity}; scale → {scaleMin, scaleMax, ...}; currency →
            // {currency}. Nulo = sin opciones (valores por defecto del tipo).
            $table->json('config')->nullable()->after('visibility');
        });
    }

    public function down(): void
    {
        Schema::table('activity_type_fields', function (Blueprint $table) {
            $table->dropColumn('config');
        });
    }
};
