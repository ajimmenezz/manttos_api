<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_statuses', function (Blueprint $table) {
            // Si un estado lo exige, no se puede pasar a él sin capturar los campos
            // obligatorios del formulario del evento (evita "resolver" sin documentar).
            $table->boolean('requires_form')->default(false)->after('is_terminal');
        });

        // Por defecto, los estados de resolución/cierre exigen el formulario.
        DB::table('event_statuses')->whereIn('key', ['resuelto', 'cerrado'])
            ->update(['requires_form' => true]);
    }

    public function down(): void
    {
        Schema::table('event_statuses', function (Blueprint $table) {
            $table->dropColumn('requires_form');
        });
    }
};
