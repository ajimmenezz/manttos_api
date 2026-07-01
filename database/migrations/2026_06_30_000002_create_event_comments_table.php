<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            // Hilos anidados: una respuesta apunta a su comentario padre.
            $table->foreignId('parent_id')->nullable()->constrained('event_comments')->cascadeOnDelete();
            $table->text('body');
            $table->softDeletes(); // borrado lógico: conserva el hilo ("comentario eliminado")
            $table->timestamps();
            $table->index(['event_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_comments');
    }
};
