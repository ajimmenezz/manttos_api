<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fecha en que el evento quedó asignado a su responsable. Para eventos creados por un
 * ingeniero (auto-asignación) equivale a la fecha de creación; para asignaciones
 * manuales, el momento de asignar. Nullable: un evento en el pool no la tiene.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->timestamp('assigned_at')->nullable()->after('assigned_to');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('assigned_at');
        });
    }
};
