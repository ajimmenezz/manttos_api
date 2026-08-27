<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Errores de la app móvil, reportados por el propio teléfono.
 *
 * Existe porque el ingeniero en campo no puede diagnosticar nada: la app le dice
 * «no se pudo subir» y ahí termina el rastro. Aquí queda el detalle técnico —el
 * que NO tiene sentido mostrarle a él— junto con quién, qué teléfono, qué versión
 * y en qué pantalla, para poder seguirlo desde la plataforma.
 *
 * `fingerprint` agrupa las repeticiones del MISMO error (tipo + contexto + mensaje
 * normalizado): un fallo que le pega a diez ingenieros es una sola cosa que
 * atender, no diez. Resolver marca todo el grupo.
 *
 * Retención de 90 días, igual que la auditoría (comando `app-errors:prune`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_error_logs', function (Blueprint $table) {
            $table->id();
            // Puede no haber usuario: un error en la pantalla de acceso no tiene sesión.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Identidad del teléfono: el mismo UUID que la app manda al iniciar sesión.
            $table->string('device_id', 64)->nullable();
            $table->string('device_name', 120)->nullable();
            $table->string('device_model', 120)->nullable();
            $table->string('platform', 16)->nullable();     // android | ios
            $table->string('os_version', 40)->nullable();
            $table->string('app_version', 20)->nullable();
            $table->string('build', 20)->nullable();

            $table->string('kind', 16)->default('handled'); // crash | api | upload | handled
            $table->string('context', 120)->nullable();     // pantalla o acción
            $table->string('message', 500);
            $table->text('detail')->nullable();             // stack o cuerpo de la respuesta

            $table->string('http_method', 10)->nullable();
            $table->string('http_url', 500)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();

            $table->string('fingerprint', 64);
            $table->timestamp('occurred_at')->nullable();   // cuándo pasó EN el teléfono
            $table->timestamp('created_at')->nullable();    // cuándo llegó al servidor

            // Seguimiento: se cierra por grupo, no fila por fila.
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable();

            $table->index('created_at');
            $table->index(['fingerprint', 'created_at']);
            $table->index(['device_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['resolved_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_error_logs');
    }
};
