<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-tenant (white-label) por dominio: cada ajuste se guarda por `tenant`
 * (= dominio normalizado). El tenant `default` provee los valores base que el
 * resto de dominios heredan/sobrescriben. La PK pasa de `key` a (`tenant`,`key`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->string('tenant', 191)->default('default')->after('key');
        });

        // Las filas existentes son la base compartida.
        DB::table('app_settings')->whereNull('tenant')->update(['tenant' => 'default']);

        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropPrimary('app_settings_pkey');
            $table->primary(['tenant', 'key']);
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropPrimary();
        });

        // Conservar solo la base para poder restaurar la PK sobre `key`.
        DB::table('app_settings')->where('tenant', '!=', 'default')->delete();

        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropColumn('tenant');
        });

        Schema::table('app_settings', function (Blueprint $table) {
            $table->primary('key');
        });
    }
};
