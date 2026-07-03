<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tiempo estándar (minutos) que toma UNA tarea = una actividad sobre un
        // dispositivo, por (sistema × tipo de dispositivo × tipo de actividad).
        // La presencia de la fila = "esta tarea aplica a ese tipo de dispositivo".
        Schema::create('task_durations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('system_id')->constrained('catalogs')->cascadeOnDelete();
            $table->foreignId('device_type_id')->constrained('catalogs')->cascadeOnDelete();
            $table->foreignId('activity_type_id')->constrained('catalogs')->cascadeOnDelete();
            $table->unsignedInteger('minutes'); // minutos por tarea
            $table->timestamps();

            $table->unique(['system_id', 'device_type_id', 'activity_type_id'], 'task_dur_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_durations');
    }
};
