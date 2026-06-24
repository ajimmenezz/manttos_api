<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un dispositivo solo puede estar sembrado en UN plano (en todo el sitio).
     * Antes el único era (floor_plan_id, device_id) → permitía el mismo
     * dispositivo en planos distintos. Ahora el único es device_id.
     */
    public function up(): void
    {
        Schema::table('device_placements', function (Blueprint $table) {
            $table->dropUnique(['floor_plan_id', 'device_id']);
            $table->unique('device_id');
        });
    }

    public function down(): void
    {
        Schema::table('device_placements', function (Blueprint $table) {
            $table->dropUnique(['device_id']);
            $table->unique(['floor_plan_id', 'device_id']);
        });
    }
};
