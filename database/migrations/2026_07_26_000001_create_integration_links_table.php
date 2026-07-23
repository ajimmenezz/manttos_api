<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liga un recurso local (p. ej. un evento) con su contraparte en el sistema externo
 * (p. ej. un issue de Jira). Así, tras crear el ticket, los cambios posteriores (estado,
 * comentarios) actualizan el MISMO ticket en vez de crear otro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->constrained('integrations')->cascadeOnDelete();
            $table->string('provider')->index();        // 'jira' | 'odoo'
            $table->string('local_type');               // 'event'
            $table->unsignedBigInteger('local_id');
            $table->string('external_key')->nullable(); // 'SUP-123'
            $table->string('external_id')->nullable();
            $table->string('external_url')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['integration_id', 'local_type', 'local_id'], 'integration_links_scope_unique');
            $table->index(['provider', 'external_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_links');
    }
};
