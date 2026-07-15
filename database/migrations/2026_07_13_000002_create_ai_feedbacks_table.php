<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calificación de las respuestas del asistente (👍/👎 + comentario). Es la
 * materia prima para mejorar el prompt, curar ejemplos "buenos" (few-shot) y,
 * a futuro, un posible fine-tuning de un modelo local.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_feedbacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_interaction_id')->constrained('ai_interactions')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('rating', 10);           // good | bad
            $table->text('comment')->nullable();

            $table->timestamps();

            // Un usuario deja una sola calificación por interacción (puede cambiarla).
            $table->unique(['ai_interaction_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_feedbacks');
    }
};
