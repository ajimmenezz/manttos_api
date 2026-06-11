<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_type_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_type_id')->constrained('catalogs')->cascadeOnDelete();
            $table->foreignId('system_id')->constrained('catalogs')->cascadeOnDelete();
            $table->string('label', 100);
            $table->string('field_key', 60);
            $table->string('field_type', 20);
            $table->string('catalog_type', 60)->nullable();
            $table->boolean('is_required')->default(false);
            $table->unsignedSmallInteger('max_length')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['activity_type_id', 'system_id', 'field_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_type_fields');
    }
};
