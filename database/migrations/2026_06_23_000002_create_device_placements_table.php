<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_placements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('floor_plan_id')->constrained('floor_plans')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            // Coordenadas NORMALIZADAS 0..1 respecto a la imagen natural (independientes del zoom/tamaño en pantalla)
            $table->decimal('x', 8, 6);
            $table->decimal('y', 8, 6);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Un dispositivo se ubica una sola vez por plano
            $table->unique(['floor_plan_id', 'device_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_placements');
    }
};
