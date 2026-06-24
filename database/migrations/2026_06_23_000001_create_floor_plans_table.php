<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('floor_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('name');                          // ej. "Piso 1", "Área de máquinas"
            $table->string('image_url');                     // URL pública (subida vía MediaController)
            $table->unsignedInteger('image_width')->nullable();  // dimensiones naturales de la imagen
            $table->unsignedInteger('image_height')->nullable(); // (para mantener proporción al renderizar)
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('floor_plans');
    }
};
