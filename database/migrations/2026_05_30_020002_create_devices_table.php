<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('directory_id')->constrained('directories')->cascadeOnDelete();
            $table->string('name');                        // identificador / nombre del dispositivo
            $table->string('device_type');                 // label del catálogo tipo_dispositivo
            $table->string('brand')->nullable();           // marca
            $table->string('model')->nullable();           // modelo
            $table->string('serial_number')->nullable();   // número de serie
            $table->string('location')->nullable();        // ubicación física dentro del sitio
            $table->string('status')->default('operativo'); // operativo | en_mantenimiento | inoperativo | dado_de_baja
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
