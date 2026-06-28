<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Auditoría de cambios de estado (base de flujos/SLA fase 3).
        Schema::create('event_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('from_status_id')->nullable()->constrained('event_statuses')->nullOnDelete();
            $table->foreignId('to_status_id')->constrained('event_statuses')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('event_id');
        });

        // Contador atómico de folio por cliente (period = año si reset_yearly, si no 'all').
        Schema::create('event_folio_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('period', 12)->default('all');
            $table->unsignedInteger('last_seq')->default(0);
            $table->timestamps();
            $table->unique(['client_id', 'period']);
        });

        // Configuración de nomenclatura de folio por cliente.
        // { prefix: "HILT", include_year: true, pad: 4, reset_yearly: true }
        Schema::table('clients', function (Blueprint $table) {
            $table->json('event_folio_config')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('event_folio_config');
        });
        Schema::dropIfExists('event_folio_counters');
        Schema::dropIfExists('event_status_history');
    }
};
