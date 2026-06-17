<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_frequencies', function (Blueprint $table) {
            // 'as_needed' (cada que sea necesario) no lleva valor numérico.
            $table->unsignedInteger('period_value')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_frequencies', function (Blueprint $table) {
            $table->unsignedInteger('period_value')->nullable(false)->change();
        });
    }
};
