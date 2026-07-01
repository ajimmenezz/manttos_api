<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_statuses', function (Blueprint $table) {
            // Si un estado lo exige, el cambio a ese estado requiere una nota.
            // Si no (default), el cambio se ejecuta directo y solo se guarda el histórico.
            $table->boolean('requires_note')->default(false)->after('requires_form');
        });
    }

    public function down(): void
    {
        Schema::table('event_statuses', function (Blueprint $table) {
            $table->dropColumn('requires_note');
        });
    }
};
