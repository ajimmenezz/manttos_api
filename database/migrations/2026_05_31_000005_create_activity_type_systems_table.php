<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('activity_type_systems', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_type_id')->constrained('catalogs')->cascadeOnDelete();
            $table->foreignId('system_id')->constrained('catalogs')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['activity_type_id', 'system_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_type_systems');
    }
};
