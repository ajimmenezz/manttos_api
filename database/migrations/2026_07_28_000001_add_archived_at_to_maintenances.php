<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archivar mantenimientos: un mantenimiento con archived_at deja de aparecer en las
 * listas (por sitio y "mis mantenimientos"), pero se conserva (reversible). Flag propio,
 * NO SoftDeletes, para no romper relaciones con un global scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->index()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('maintenances', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
