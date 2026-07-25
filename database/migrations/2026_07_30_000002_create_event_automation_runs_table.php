<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de ejecución de las automatizaciones de evento. Sirve para (1) el dedupe de
 * `run_once` (una automatización solo corre una vez por evento) y (2) dejar traza de lo
 * ocurrido (éxito / error / omitida) con el resultado del sistema externo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_automation_id')->constrained('event_type_automations')->cascadeOnDelete();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('status', 12);          // success | failed | skipped
            $table->jsonb('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('ran_at')->useCurrent();
            $table->index(['event_automation_id', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_automation_runs');
    }
};
