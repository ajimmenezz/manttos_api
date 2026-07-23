<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archivar eventos: un evento con archived_at deja de aparecer en la interfaz y en la
 * reportería (la exclusión la aplica ScopesEvents, punto único de ambos). Reversible.
 * NO es SoftDeletes (no queremos un global scope que rompa relaciones); solo un flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->index()->after('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
