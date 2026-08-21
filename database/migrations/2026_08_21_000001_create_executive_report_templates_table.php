<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plantillas del reporte ejecutivo: qué bloques lleva y por qué campos del directorio
 * agrupa, guardado por sitio (y sistema) para no rearmarlo cada mes.
 * `config` = la estructura que consume App\Services\Reports\ExecutiveReport.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('executive_report_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->unsignedBigInteger('system_id')->nullable();   // catalogs.id; null = todos los sistemas
            $table->string('name', 120);
            $table->json('config');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['site_id', 'system_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('executive_report_templates');
    }
};
