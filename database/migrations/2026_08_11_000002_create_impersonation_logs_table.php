<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de suplantación: quién (superadmin) trabajó como quién, desde qué IP y por
 * cuánto tiempo. `token_id` liga al PersonalAccessToken emitido para la sesión suplantada
 * (para revocarlo al terminar). Es solo auditoría; no interviene en la autorización.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impersonation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('impersonator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('impersonated_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('token_id')->nullable()->index(); // sanctum PAT emitido
            $table->string('ip', 45)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['impersonator_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonation_logs');
    }
};
