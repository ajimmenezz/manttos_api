<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de auditoría del asistente de IA: cada turno del chat queda con
 * quién, cuándo, qué pidió, qué respondió, cuánto demoró, tokens y costo, más
 * las herramientas ejecutadas. Base para observabilidad, control de gasto y
 * recolección de datos para mejora/entrenamiento futuro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_interactions', function (Blueprint $table) {
            $table->id();

            // Conversación (agrupa varios turnos de un mismo hilo de chat).
            $table->uuid('conversation_id')->nullable()->index();

            // Quién y cuándo.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Qué pidió / qué respondió.
            $table->text('prompt');
            $table->text('reply')->nullable();

            // Proveedor y modelo usados.
            $table->string('provider', 40)->nullable();
            $table->string('model', 120)->nullable();

            // Consumo y costo (precios de lista al momento de la llamada).
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);   // costo estimado en USD
            $table->decimal('price_in', 10, 4)->nullable();   // USD/1M entrada aplicado
            $table->decimal('price_out', 10, 4)->nullable();  // USD/1M salida aplicado

            // Desempeño.
            $table->unsignedInteger('duration_ms')->default(0);
            $table->unsignedSmallInteger('iterations')->default(0);

            // Herramientas ejecutadas y estado.
            $table->jsonb('actions')->nullable();
            $table->string('status', 20)->default('ok');      // ok | error
            $table->text('error')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_interactions');
    }
};
