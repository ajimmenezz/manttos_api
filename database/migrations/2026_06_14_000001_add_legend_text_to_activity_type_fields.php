<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_type_fields', function (Blueprint $table) {
            // Contenido del campo de tipo "leyenda": texto fijo no editable que se
            // muestra en la captura de la actividad. Nulo para los demás tipos.
            $table->text('legend_text')->nullable()->after('catalog_type');
        });
    }

    public function down(): void
    {
        Schema::table('activity_type_fields', function (Blueprint $table) {
            $table->dropColumn('legend_text');
        });
    }
};
