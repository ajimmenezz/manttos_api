<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_type_fields', function (Blueprint $table) {
            // Reglas condicionales: lista ordenada de { group, value }. Si un grupo de
            // condiciones (anidable, Y/O) se cumple, el campo toma ese value. Se evalúan
            // contra los otros campos del mismo formulario. Nulo = sin reglas.
            $table->json('rules')->nullable()->after('legend_text');
        });
    }

    public function down(): void
    {
        Schema::table('activity_type_fields', function (Blueprint $table) {
            $table->dropColumn('rules');
        });
    }
};
