<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_type_fields', function (Blueprint $table) {
            // Condiciones de visibilidad / obligatoriedad: { showWhen, requireWhen }.
            // Cada uno es un grupo de condiciones (anidable, Y/O) que reutiliza el mismo
            // motor que las reglas de valor. showWhen vacío = siempre visible;
            // requireWhen vacío = usa el is_required base. Nulo = sin condiciones.
            $table->json('visibility')->nullable()->after('rules');
        });
    }

    public function down(): void
    {
        Schema::table('activity_type_fields', function (Blueprint $table) {
            $table->dropColumn('visibility');
        });
    }
};
