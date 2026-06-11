<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_fields', function (Blueprint $table) {
            $table->id();
            // catalog entry de type='system' al que pertenece esta plantilla
            $table->foreignId('catalog_id')->constrained('catalogs')->cascadeOnDelete();
            $table->string('label');                        // etiqueta visible
            $table->string('field_key');                    // clave interna (snake_case)
            // text | number | date | boolean | list
            $table->string('field_type')->default('text');
            // si field_type='list', qué tipo de catálogo cargar (ej: 'device_type')
            $table->string('catalog_type')->nullable();
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('max_length')->nullable(); // para field_type='text'
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['catalog_id', 'field_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_fields');
    }
};
