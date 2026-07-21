<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Vaciar directorio": archivar (reversible) dispositivos para que desaparezcan del
 * directorio, los selectores y los mantenimientos, PERO sigan guardados para los eventos
 * que los referencian.
 *
 * Se usa un flag propio `archived_at` (NO Laravel SoftDeletes): SoftDeletes agregaría un
 * global scope que haría que `Event::device()` (belongsTo) devolviera null en web, móvil
 * y reportes. Con este flag la relación del evento resuelve sin tocar nada; solo se
 * excluyen los archivados en las CONSULTAS de listado, que se ajustan una por una.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->index()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
