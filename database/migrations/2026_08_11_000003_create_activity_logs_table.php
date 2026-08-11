<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoría del sistema: registro detallado de las ACCIONES y CAMBIOS de cualquier
 * usuario, atado a su módulo y (cuando aplica) al registro concreto. Retención de 90
 * días (comando `activity:prune`). No registra lecturas/navegación pasiva.
 *
 * `source`: 'model' (alta/edición/borrado de un registro, con antes/después),
 * 'request' (acción HTTP mutadora sin cambio de modelo — export, etc.) o 'auth'
 * (login/logout/suplantación).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('impersonator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 16)->default('model');
            $table->string('module', 40)->nullable();
            $table->string('action', 48);
            $table->text('description')->nullable();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label')->nullable();
            $table->json('properties')->nullable();  // { old:{}, attributes:{} } | { params:{} }
            $table->string('method', 10)->nullable();
            $table->string('route')->nullable();
            $table->string('path')->nullable();
            $table->unsignedSmallInteger('status')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['user_id', 'created_at']);
            $table->index(['module', 'created_at']);
            $table->index(['subject_type', 'subject_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
