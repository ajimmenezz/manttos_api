<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Formulario de captura por (tipo de evento × sistema). Misma forma que activity_type_fields
        // para reutilizar el motor de campos dinámicos (tipos, reglas, leyenda, config).
        Schema::create('event_type_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_type_id')->constrained('event_types')->cascadeOnDelete();
            $table->foreignId('system_id')->constrained('catalogs')->cascadeOnDelete();
            $table->string('label', 100);
            $table->string('field_key', 60);
            $table->string('field_type', 20);
            $table->string('catalog_type', 60)->nullable();
            $table->text('legend_text')->nullable();
            $table->json('rules')->nullable();
            $table->json('visibility')->nullable();
            $table->json('config')->nullable();
            $table->boolean('is_required')->default(false);
            $table->unsignedSmallInteger('max_length')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['event_type_id', 'system_id', 'field_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_type_fields');
    }
};
