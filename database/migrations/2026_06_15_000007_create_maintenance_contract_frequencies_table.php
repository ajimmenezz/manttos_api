<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_contract_frequencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('maintenance_id')->constrained('maintenances')->cascadeOnDelete();
            $table->foreignId('device_type_id')->constrained('catalogs')->cascadeOnDelete();
            $table->foreignId('activity_type_id')->constrained('catalogs')->cascadeOnDelete();
            $table->unsignedInteger('period_value')->nullable(); // null para 'as_needed'
            $table->string('period_unit', 10); // days | months | years | as_needed
            $table->timestamps();

            $table->unique(['maintenance_id', 'device_type_id', 'activity_type_id'], 'maint_contract_freq_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_contract_frequencies');
    }
};
