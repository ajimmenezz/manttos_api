<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fotos del levantamiento del evento + diagnóstico de apoyo por IA.
 *
 * - `images`: URLs públicas (las sube MediaController a maintenance-media/), para
 *   adjuntar evidencia al levantar el evento, además de la descripción.
 * - `ai_diagnosis`: diagnóstico ORIENTATIVO generado por la IA a partir de las
 *   imágenes + descripción + sistema/equipo, cruzado con la base de conocimiento.
 *   Es apoyo para el ingeniero, NO un veredicto. `ai_diagnosis_at` = cuándo se generó.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->json('images')->nullable()->after('field_values');
            $table->json('ai_diagnosis')->nullable()->after('images');
            $table->timestamp('ai_diagnosis_at')->nullable()->after('ai_diagnosis');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['images', 'ai_diagnosis', 'ai_diagnosis_at']);
        });
    }
};
