<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_fields', function (Blueprint $table) {
            // null = campo base del sistema · valor = campo extra exclusivo del cliente
            $table->foreignId('client_id')
                ->nullable()
                ->after('catalog_id')
                ->constrained('clients')
                ->cascadeOnDelete();

            // El unique existente (catalog_id, field_key) ya no cubre los casos
            // de cliente → lo eliminamos y sustituimos por dos índices parciales
            $table->dropUnique('system_fields_catalog_id_field_key_unique');
        });

        // Campos base: (catalog_id, field_key) únicos cuando client_id IS NULL
        DB::statement('
            CREATE UNIQUE INDEX system_fields_base_unique
            ON system_fields (catalog_id, field_key)
            WHERE client_id IS NULL
        ');

        // Campos de cliente: (catalog_id, field_key, client_id) únicos cuando client_id IS NOT NULL
        DB::statement('
            CREATE UNIQUE INDEX system_fields_client_unique
            ON system_fields (catalog_id, field_key, client_id)
            WHERE client_id IS NOT NULL
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS system_fields_base_unique');
        DB::statement('DROP INDEX IF EXISTS system_fields_client_unique');

        Schema::table('system_fields', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
            $table->dropColumn('client_id');
            $table->unique(['catalog_id', 'field_key']);
        });
    }
};
