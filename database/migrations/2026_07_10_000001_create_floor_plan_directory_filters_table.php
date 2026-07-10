<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Filtros fijos de un plano por directorio: "este plano pertenece a las áreas 1,2,3".
 * Se scopea por (floor_plan, directory) porque los campos del directorio dependen del
 * sistema de cada directorio. `filters` = { field_key: ["v1","v2",...], ... }.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('floor_plan_directory_filters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('floor_plan_id')->constrained('floor_plans')->cascadeOnDelete();
            $table->foreignId('directory_id')->constrained('directories')->cascadeOnDelete();
            $table->json('filters');   // { "area": ["1","2","3"], ... }
            $table->timestamps();

            $table->unique(['floor_plan_id', 'directory_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('floor_plan_directory_filters');
    }
};
