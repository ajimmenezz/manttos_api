<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // Los campos fijos pasan a ser opcionales; la plantilla del sistema
            // define qué se captura y los valores van en custom_fields (JSONB).
            $table->string('name')->nullable()->change();
            $table->string('device_type')->nullable()->change();
            $table->string('status')->nullable()->default('operativo')->change();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('name')->nullable(false)->change();
            $table->string('device_type')->nullable(false)->change();
            $table->string('status')->nullable(false)->default('operativo')->change();
        });
    }
};
