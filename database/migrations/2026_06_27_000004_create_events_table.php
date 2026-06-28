<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('folio', 40)->unique();              // por cliente, nomenclatura configurable
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('system_id')->constrained('catalogs')->cascadeOnDelete();   // sistema
            $table->foreignId('event_type_id')->constrained('event_types')->restrictOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete(); // opcional
            $table->foreignId('status_id')->constrained('event_statuses')->restrictOnDelete();
            $table->string('priority', 10)->default('media');   // baja | media | alta | critica
            $table->text('description');
            $table->json('field_values')->nullable();           // formulario dinámico capturado
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at')->nullable();       // cuándo ocurrió (puede diferir de created_at)
            $table->timestamps();

            $table->index(['client_id', 'status_id']);
            $table->index(['site_id', 'system_id']);
            $table->index('event_type_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
