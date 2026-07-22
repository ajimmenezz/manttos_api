<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backbone de integraciones a sistemas externos (Odoo, Jira Service Management, …).
 *
 * `integrations`  — una fila por proveedor y alcance. client_id NULL = configuración
 *                   GLOBAL de la plataforma; una fila con client_id = configuración de
 *                   ESE cliente (override que gana sobre la global). Apagadas por
 *                   defecto (is_active=false): si no hay fila activa y configurada, el
 *                   despachador simplemente no hace nada.
 * `integration_logs` — bitácora unificada de salida (nuestros eventos → externo),
 *                   entrada (webhooks entrantes) y consultas (p. ej. inventario Odoo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->index();              // 'odoo' | 'jira'
            $table->foreignId('client_id')->nullable()        // NULL = global
                ->constrained('clients')->nullOnDelete();
            $table->boolean('is_active')->default(false);
            $table->text('config')->nullable();               // JSON cifrado (credenciales, URLs, mapeos)
            $table->string('inbound_secret')->nullable();     // verifica llamadas entrantes
            $table->timestamp('last_ok_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->text('last_error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Un proveedor por alcance. (client_id NULL se maneja además en el controlador
            // vía updateOrCreate, porque MySQL considera los NULL distintos entre sí.)
            $table->unique(['provider', 'client_id']);
        });

        Schema::create('integration_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_id')->nullable()->constrained('integrations')->nullOnDelete();
            $table->string('provider')->index();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->string('direction')->default('outbound'); // outbound | inbound | query
            $table->string('event_type');                     // 'event.created', 'inbound', 'ping', ...
            $table->string('status')->default('pending')->index(); // pending | success | failed | skipped
            $table->unsignedInteger('attempts')->default(0);
            $table->json('payload')->nullable();
            $table->json('response')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['provider', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_logs');
        Schema::dropIfExists('integrations');
    }
};
