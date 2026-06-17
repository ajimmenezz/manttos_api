<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_frequencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('system_id')->constrained('catalogs')->cascadeOnDelete();
            $table->foreignId('device_type_id')->constrained('catalogs')->cascadeOnDelete();
            $table->foreignId('activity_type_id')->constrained('catalogs')->cascadeOnDelete();
            // Cada cuánto debería registrarse la actividad: valor + unidad.
            $table->unsignedInteger('period_value');
            $table->string('period_unit', 10); // 'days' | 'months' | 'years'
            $table->timestamps();

            $table->unique(['system_id', 'device_type_id', 'activity_type_id'], 'maint_freq_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_frequencies');
    }
};
