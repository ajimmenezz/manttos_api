<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plantillas de EXPORTACIÓN de los reportes-tablero.
 *
 * Guardan lo que se vuelve a elegir en cada descarga —qué bloques se imprimen y cómo va
 * la firma—, no los filtros: el periodo y el sitio cambian por definición en cada
 * reporte, mientras que «el reporte que le mando al cliente» siempre lleva los mismos
 * bloques.
 *
 * Son POR USUARIO: cada quien arma los suyos («mi plantilla»), y así una no le cambia el
 * documento a otro sin avisar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_export_templates', function (Blueprint $table) {
            $table->id();

            // 'events' | 'maintenances' — las llaves de App\Support\ReportSections.
            $table->string('report', 20);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);

            // Llaves de las secciones ELEGIDAS (no las excluidas): una plantilla significa
            // «exactamente estos bloques», así que un bloque nuevo no se cuela solo.
            $table->jsonb('sections');

            $table->string('signature', 10)->nullable();
            $table->string('signature_align', 10)->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'report', 'name']);
            $table->index(['user_id', 'report']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_export_templates');
    }
};
