<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La PWA (versión web para iPhone) registra como "token" su suscripción de Web
 * Push completa en JSON: endpoint del navegador + llaves de cifrado. Mide entre
 * 300 y 450 caracteres según el navegador, y el tope de 500 quedaba justo. Se lleva
 * a 1000; en PostgreSQL ampliar un varchar no reescribe la tabla.
 *
 * `provider` ya era varchar(10): 'webpush' cabe sin cambio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->string('token', 1000)->change();
        });
    }

    public function down(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->string('token', 500)->change();
        });
    }
};
