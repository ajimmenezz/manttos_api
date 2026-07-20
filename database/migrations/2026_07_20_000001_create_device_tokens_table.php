<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tokens de push por dispositivo (fase 2 del chat).
 *
 * El token es ÚNICO pero NO pertenece para siempre al mismo usuario: si dos personas
 * usan el mismo teléfono, al iniciar sesión el token se reasigna. Por eso el registro
 * es un upsert por `token` y no un insert por usuario — si no, el segundo usuario
 * recibiría los push del primero.
 *
 * `provider` deja abierto el camino de iOS: hoy todo va por FCM (que también entrega
 * a APNs), pero si algún día se manda a APNs directo, se distingue aquí sin migrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token', 500)->unique();
            $table->string('platform', 10);                  // 'android' | 'ios' | 'web'
            $table->string('provider', 10)->default('fcm');  // 'fcm' | 'apns'
            $table->string('app_version', 20)->nullable();
            $table->string('device_name', 120)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            // Envío: "todos los tokens vivos de estos usuarios".
            $table->index(['user_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
