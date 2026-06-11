<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_type_fields', function (Blueprint $table) {
            $table->boolean('show_in_bitacora')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('activity_type_fields', function (Blueprint $table) {
            $table->dropColumn('show_in_bitacora');
        });
    }
};
