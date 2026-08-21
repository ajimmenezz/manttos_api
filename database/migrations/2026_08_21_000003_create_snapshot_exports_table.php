<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitudes de respaldo completo. El ZIP se arma en segundo plano (Job
 * GenerateSnapshot) y esta tabla es lo que la pantalla consulta para saber si ya
 * está listo; al terminar se avisa por la campanita.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('snapshot_exports', function (Blueprint $table) {
            $table->id();
            $table->string('status', 20)->default('pending'); // pending|processing|done|failed
            $table->string('file_name');
            $table->unsignedBigInteger('size')->nullable();
            $table->boolean('no_media')->default(false);
            $table->text('error')->nullable();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['requested_by', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('snapshot_exports');
    }
};
