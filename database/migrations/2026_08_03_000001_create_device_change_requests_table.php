<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitudes de cambio a los datos del directorio de un dispositivo, hechas desde un evento.
 * El ingeniero SOLICITA (no aplica nada); quien tiene el permiso de aplicar aprueba y recién
 * entonces se escriben los `custom_fields`. Cada solicitud guarda el antes/después completo
 * (auditoría): quién, cuándo, cómo se llamaba antes y cómo quedó.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->foreignId('requested_by')->constrained('users');
            // [{field_key, label, field_type, old, new}] — el detalle del cambio por campo.
            $table->json('changes');
            $table->text('note')->nullable();
            $table->string('status', 20)->default('pending'); // pending | applied | rejected
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->index(['device_id', 'status']);
            $table->index(['event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_change_requests');
    }
};
