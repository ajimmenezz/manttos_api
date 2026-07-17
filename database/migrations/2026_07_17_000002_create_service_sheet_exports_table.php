<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitudes de exportación en ZIP de las hojas de servicio de los eventos de un
 * cliente en un rango de fechas (≤ 31 días). Se procesan en segundo plano (cola) y al
 * terminar notifican al usuario con el enlace de descarga.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_sheet_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->string('status', 20)->default('pending'); // pending|processing|done|failed
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('event_count')->nullable();
            $table->string('file_path', 255)->nullable(); // ruta relativa en el disco 'local'
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['requested_by', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_sheet_exports');
    }
};
