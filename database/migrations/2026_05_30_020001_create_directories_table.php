<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            // catalog entry de type='system' (Sistema de TI, Detección de Incendio, etc.)
            $table->foreignId('catalog_id')->constrained('catalogs')->restrictOnDelete();
            $table->string('name')->nullable();           // nombre personalizado opcional
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Un sitio solo puede tener un directorio por sistema
            $table->unique(['site_id', 'catalog_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('directories');
    }
};
