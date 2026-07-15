<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alcance de los solicitantes (usuarios de portal). Pivotes DEDICADOS —no reusar
 * client_user/site_user, que son de administradores y contaminarían esas listas—:
 * un solicitante puede estar asociado a uno o varios clientes y/o sitios; ahí puede
 * levantar reportes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitante_client', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['user_id', 'client_id']);
        });

        Schema::create('solicitante_site', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['user_id', 'site_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitante_client');
        Schema::dropIfExists('solicitante_site');
    }
};
