<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_engineers', function (Blueprint $table) {
            $table->foreignId('maintenance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['maintenance_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_engineers');
    }
};
