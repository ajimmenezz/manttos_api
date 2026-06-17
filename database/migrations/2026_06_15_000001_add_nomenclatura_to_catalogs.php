<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalogs', function (Blueprint $table) {
            // Nomenclatura/código corto de un tipo de dispositivo (ej. "BS", "DH"),
            // usada para componer DIDs por patrón. Nulo para los demás tipos de catálogo.
            $table->string('nomenclatura', 20)->nullable()->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('catalogs', function (Blueprint $table) {
            $table->dropColumn('nomenclatura');
        });
    }
};
