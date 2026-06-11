<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            // Sistema al que aplica el mantenimiento (Catalog type='system')
            $table->foreignId('catalog_id')->constrained('catalogs')->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            // programado | en_curso | completado | cancelado
            $table->string('status', 20)->default('programado');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['site_id', 'catalog_id']);
            $table->index(['site_id', 'start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenances');
    }
};
