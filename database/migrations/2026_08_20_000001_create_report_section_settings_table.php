<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué secciones de los reportes-tablero ve cada ROL y cada USUARIO.
 * `overrides` = { "<section_key>": true|false }. El rol sólo guarda los `false`
 * (lo que oculta); el usuario puede guardar `true` para recuperar algo que su rol
 * oculta. Ausente = hereda (visible).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_section_settings', function (Blueprint $table) {
            $table->id();
            $table->string('scope_type', 10);          // 'role' | 'user'
            $table->unsignedBigInteger('scope_id');
            $table->string('report', 40);              // 'events' | 'maintenances'
            $table->json('overrides');
            $table->timestamps();

            $table->unique(['scope_type', 'scope_id', 'report']);
            $table->index(['report', 'scope_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_section_settings');
    }
};
