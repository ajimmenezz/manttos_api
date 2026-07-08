<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reglas de orden/prioridad para la agenda propuesta del plan de acción.
        // JSON: { rules: [ { dim, field_key?, order:[...] }, ... ] }. NULL = sin orden.
        Schema::table('maintenances', function (Blueprint $table) {
            $table->json('agenda_rules')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropColumn('agenda_rules');
        });
    }
};
