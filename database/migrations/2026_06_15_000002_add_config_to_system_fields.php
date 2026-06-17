<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_fields', function (Blueprint $table) {
            // Configuración extra del campo. Para field_type='did':
            //   { did_mode: 'number'|'text'|'pattern', pad_length?: int, pattern?: token[] }
            $table->json('config')->nullable()->after('catalog_type');
        });
    }

    public function down(): void
    {
        Schema::table('system_fields', function (Blueprint $table) {
            $table->dropColumn('config');
        });
    }
};
