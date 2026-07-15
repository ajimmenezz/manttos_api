<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de correos enviados: alimenta el contador diario y el tope máximo de
 * envíos configurable (para no caer en spam / bloqueos del proveedor SMTP).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_send_logs', function (Blueprint $table) {
            $table->id();
            $table->string('to_email')->nullable();
            $table->string('subject')->nullable();
            $table->string('mailable')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_send_logs');
    }
};
