<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogos/listas REUTILIZABLES definidos por el usuario. A diferencia de las
 * opciones inline de un campo "Lista personalizada", estos viven aparte y se
 * referencian desde varios formularios. `client_id` nullable = catálogo GLOBAL;
 * con cliente = solo para ese cliente. Opciones (etiqueta+valor) en JSON.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_catalogs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->jsonb('options')->default('[]'); // [{label, value}]
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_catalogs');
    }
};
