<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalogs', function (Blueprint $table) {
            $table->id();
            $table->string('type');          // 'industry' | 'site_type'
            $table->string('label');         // Texto visible: "Hotelería", "Hotel"...
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('type');
            $table->unique(['type', 'label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalogs');
    }
};
