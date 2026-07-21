<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preferencias de notificación por usuario y por tipo de escenario.
 *
 * Solo se guarda una fila cuando el usuario cambia el default del catálogo (ver
 * App\Support\NotificationType). Sin fila = default del catálogo. Así un escenario
 * nuevo queda encendido para todos sin tocar esta tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 64);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
