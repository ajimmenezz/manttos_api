<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reportes visuales generados por el asistente (HTML autocontenido, descargable).
 * La IA arma los datos con sus herramientas y `crear_reporte` renderiza el HTML,
 * que se guarda aquí y se sirve autenticado por su dueño.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('conversation_id')->nullable()->index();
            $table->string('title');
            $table->longText('html');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_reports');
    }
};
