<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_device_types', function (Blueprint $table) {
            $table->id();
            // catalog entry de type='system'
            $table->foreignId('system_catalog_id')->constrained('catalogs')->cascadeOnDelete();
            // catalog entry de type='device_type'
            $table->foreignId('device_type_catalog_id')->constrained('catalogs')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['system_catalog_id', 'device_type_catalog_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_device_types');
    }
};
