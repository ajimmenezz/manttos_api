<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Llave de origen para la importación idempotente de eventos desde sistemas externos
 * (p. ej. ADIST3: `adist3:<Id>`). Mismo patrón que maintenance_activities.source_ref.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('source_ref', 100)->nullable()->unique()->after('client_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropUnique(['source_ref']);
            $table->dropColumn('source_ref');
        });
    }
};
