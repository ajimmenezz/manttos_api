<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Niveles de atención de la matriz de SLA (p. ej. Atención Remota N1, En sitio,
        // Especializada). Cada nivel tendrá una hora objetivo por prioridad, y se enlaza
        // a los estados de evento que representan "esa atención ya se dio".
        Schema::create('event_sla_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();     // remota_n1 | en_sitio | especializada
            $table->string('label', 120);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_sla_tiers');
    }
};
