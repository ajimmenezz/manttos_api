<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trazabilidad + idempotencia para actividades importadas de sistemas externos (ADIST3).
 * source_ref = "adist3:{taskId}" → no duplicar al re-correr y saber de dónde vino.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_activities', function (Blueprint $table) {
            $table->string('source_ref')->nullable()->index()->after('performed_at');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_activities', function (Blueprint $table) {
            $table->dropColumn('source_ref');
        });
    }
};
